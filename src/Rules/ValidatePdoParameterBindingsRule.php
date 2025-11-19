<?php declare(strict_types=1);

namespace Pierresh\PhpStanPdoMysql\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Scalar\Encapsed;
use PhpParser\Node\Scalar\EncapsedStringPart;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * This rule validates PDO parameter bindings for both class properties and local variables.
 *
 * Patterns it validates:
 *
 * 1. Class properties across methods:
 *    - $this->query = $db->prepare("... :param ...") in __construct()
 *    - $this->query->bindValue(':param', $value) in execute() method
 *    - $this->query->execute() in execute() method
 *
 * 2. Local variables within a method:
 *    - $query = $db->prepare("... :param ...")
 *    - $query->bindValue(':param', $value) or $query->execute(['param' => value])
 *
 * @implements Rule<Class_>
 */
class ValidatePdoParameterBindingsRule implements Rule
{
	public function getNodeType(): string
	{
		return Class_::class;
	}

	public function processNode(Node $node, Scope $scope): array
	{
		$errors = [];

		// 1. Validate class properties across methods
		$errors = array_merge($errors, $this->validateClassProperties($node));

		// 2. Validate local variables within each method
		$errors = array_merge($errors, $this->validateLocalVariables($node));

		return $errors;
	}

	/**
	 * Validate class properties (e.g., $this->query) across methods
	 *
	 * @return array<\PHPStan\Rules\RuleError>
	 */
	private function validateClassProperties(Class_ $class): array
	{
		$propertyPreparations = $this->extractPropertyPreparations($class);

		if ($propertyPreparations === []) {
			return [];
		}

		$propertyBindings = $this->extractPropertyBindings($class);
		$executeCalls = $this->extractExecuteLocations($class);

		return $this->validateAllPropertyPreparations(
			$propertyPreparations,
			$propertyBindings,
			$executeCalls,
		);
	}

	/**
	 * Validate all property preparations against their execute calls
	 *
	 * @param array<string, array{placeholders: array<string>, line: int, sql: string}> $propertyPreparations
	 * @param array<string, array{params: array<string>, locations: array<string, int>}> $propertyBindings
	 * @param array<string, array<array{line: int, params: array<array{name: string, line: int}>|null}>> $executeCalls
	 * @return array<\PHPStan\Rules\RuleError>
	 */
	private function validateAllPropertyPreparations(
		array $propertyPreparations,
		array $propertyBindings,
		array $executeCalls,
	): array {
		$errors = [];

		foreach ($propertyPreparations as $propertyName => $info) {
			$propertyErrors = $this->validateSinglePropertyPreparation(
				$propertyName,
				$info,
				$propertyBindings,
				$executeCalls,
			);
			$errors = array_merge($errors, $propertyErrors);
		}

		return $errors;
	}

	/**
	 * Validate a single property preparation
	 *
	 * @param array{placeholders: array<string>, line: int, sql: string} $info
	 * @param array<string, array{params: array<string>, locations: array<string, int>}> $propertyBindings
	 * @param array<string, array<array{line: int, params: array<array{name: string, line: int}>|null}>> $executeCalls
	 * @return array<\PHPStan\Rules\RuleError>
	 */
	private function validateSinglePropertyPreparation(
		string $propertyName,
		array $info,
		array $propertyBindings,
		array $executeCalls,
	): array {
		$placeholders = $info['placeholders'];
		$executes = $executeCalls[$propertyName] ?? [];

		if (count($executes) === 0) {
			return [];
		}

		$errors = [];
		foreach ($executes as $execute) {
			$executeErrors = $this->validatePropertyExecuteCall(
				$execute,
				$placeholders,
				$propertyName,
				$propertyBindings,
			);
			$errors = array_merge($errors, $executeErrors);
		}

		return $errors;
	}

	/**
	 * Validate a single execute() call for a property
	 *
	 * @param array{line: int, params: array<array{name: string, line: int}>|null} $execute
	 * @param array<string> $placeholders
	 * @param array<string, array{params: array<string>, locations: array<string, int>}> $propertyBindings
	 * @return array<\PHPStan\Rules\RuleError>
	 */
	private function validatePropertyExecuteCall(
		array $execute,
		array $placeholders,
		string $propertyName,
		array $propertyBindings,
	): array {
		$executeLine = $execute['line'];
		$executeParams = $execute['params'];

		if ($executeParams !== null) {
			return $this->validateExecuteWithArray(
				$placeholders,
				$executeParams,
				$executeLine,
			);
		}

		return $this->validateExecuteWithBindings(
			$placeholders,
			$propertyName,
			$propertyBindings,
			$executeLine,
		);
	}

	/**
	 * Validate execute() call with array parameters
	 *
	 * @param array<string> $placeholders
	 * @param array<array{name: string, line: int}> $executeParams
	 * @return array<\PHPStan\Rules\RuleError>
	 */
	private function validateExecuteWithArray(
		array $placeholders,
		array $executeParams,
		int $executeLine,
	): array {
		$errors = [];

		// Extract param names and create a map of name => line
		$paramNames = [];
		$paramLines = [];
		foreach ($executeParams as $param) {
			$paramNames[] = $param['name'];
			$paramLines[$param['name']] = $param['line'];
		}

		$missing = array_diff($placeholders, $paramNames);
		$extra = array_diff($paramNames, $placeholders);

		foreach ($missing as $param) {
			$errors[] = RuleErrorBuilder::message(sprintf(
				'Missing parameter :%s in execute()',
				$param,
			))
				->line($executeLine)
				->identifier('pdoSql.missingParameter')
				->build();
		}

		foreach ($extra as $param) {
			$errors[] = RuleErrorBuilder::message(sprintf(
				'Parameter :%s in execute() is not used',
				$param,
			))
				->line($paramLines[$param]) // Use the actual parameter line, not execute line
				->identifier('pdoSql.extraParameter')
				->build();
		}

		return $errors;
	}

	/**
	 * Validate execute() call with bindValue/bindParam
	 *
	 * @param array<string> $placeholders
	 * @param array<string, array{params: array<string>, locations: array<string, int>}> $propertyBindings
	 * @return array<\PHPStan\Rules\RuleError>
	 */
	private function validateExecuteWithBindings(
		array $placeholders,
		string $propertyName,
		array $propertyBindings,
		int $executeLine,
	): array {
		$errors = [];
		$boundParams = $propertyBindings[$propertyName]['params'] ?? [];

		$missing = array_diff($placeholders, $boundParams);
		$extra = array_diff($boundParams, $placeholders);

		foreach ($missing as $param) {
			$errors[] = RuleErrorBuilder::message(sprintf(
				'Missing bindValue/bindParam for :%s',
				$param,
			))
				->line($executeLine)
				->identifier('pdoSql.missingBinding')
				->build();
		}

		if (isset($propertyBindings[$propertyName]['locations'])) {
			foreach ($extra as $param) {
				$bindingLine =
					$propertyBindings[$propertyName]['locations'][$param] ?? $executeLine;
				$errors[] = RuleErrorBuilder::message(sprintf(
					'Parameter :%s is bound but not used',
					$param,
				))
					->line($bindingLine)
					->identifier('pdoSql.extraBinding')
					->build();
			}
		}

		return $errors;
	}

	/**
	 * Validate local variables (e.g., $query) within each method
	 *
	 * @return array<\PHPStan\Rules\RuleError>
	 */
	private function validateLocalVariables(Class_ $class): array
	{
		$errors = [];

		foreach ($class->getMethods() as $classMethod) {
			$errors = array_merge(
				$errors,
				$this->validateMethodLocalVariables($classMethod),
			);
		}

		return $errors;
	}

	/**
	 * Validate local variables within a single method
	 *
	 * @return array<\PHPStan\Rules\RuleError>
	 */
	private function validateMethodLocalVariables(ClassMethod $classMethod): array
	{
		$errors = [];
		$preparations = $this->extractLocalVariablePreparations($classMethod);

		// Group preparations by variable name
		$preparationsByVar = [];
		foreach ($preparations as $preparation) {
			$varName = $preparation['var'];
			if (!isset($preparationsByVar[$varName])) {
				$preparationsByVar[$varName] = [];
			}

			$preparationsByVar[$varName][] = $preparation;
		}

		// Validate each variable's prepare/execute pairs
		foreach ($preparationsByVar as $varName => $varPreparations) {
			$prepErrors = $this->validateVariablePreparations(
				$varName,
				$varPreparations,
				$classMethod,
			);
			$errors = array_merge($errors, $prepErrors);
		}

		return $errors;
	}

	/**
	 * Validate all preparations for a single variable
	 * Matches each execute() to the most recent prepare() before it
	 *
	 * @param array<array{var: string, sql: string, line: int, placeholders: array<string>}> $preparations
	 * @return array<\PHPStan\Rules\RuleError>
	 */
	private function validateVariablePreparations(
		string $varName,
		array $preparations,
		ClassMethod $classMethod,
	): array {
		$errors = [];

		// Get all execute calls for this variable
		$executeCalls = $this->extractLocalVariableExecuteCalls(
			$classMethod,
			$varName,
		);

		if ($executeCalls === []) {
			return [];
		}

		// Sort preparations by line number (should already be in order, but ensure it)
		usort($preparations, fn($a, $b): int => $a['line'] <=> $b['line']);

		// Get bindings for this variable
		$boundParams = $this->extractLocalVariableBindings($classMethod, $varName);

		// For each execute call, find the most recent prepare() before it
		$prepCount = count($preparations);
		foreach ($executeCalls as $executeCall) {
			$executeLine = $executeCall['line'];

			// Find the most recent prepare() that comes before this execute()
			// Iterate backwards through sorted preparations (most efficient)
			$matchingPreparation = null;
			for ($i = $prepCount - 1; $i >= 0; $i--) {
				if ($preparations[$i]['line'] < $executeLine) {
					$matchingPreparation = $preparations[$i];
					break;
				}
			}

			// If no matching prepare found, skip validation
			if ($matchingPreparation === null) {
				continue;
			}

			// Validate this execute() against its matching prepare()
			$executeErrors = $this->validateLocalVariableExecuteCall(
				$executeCall,
				$matchingPreparation['placeholders'],
				$boundParams,
			);
			$errors = array_merge($errors, $executeErrors);
		}

		return $errors;
	}

	/**
	 * Validate a single execute() call for a local variable
	 *
	 * @param array{line: int, params: array<array{name: string, line: int}>|null} $executeCall
	 * @param array<string> $placeholders
	 * @param array<string> $boundParams
	 * @return array<\PHPStan\Rules\RuleError>
	 */
	private function validateLocalVariableExecuteCall(
		array $executeCall,
		array $placeholders,
		array $boundParams,
	): array {
		$executeLine = $executeCall['line'];
		$executeParams = $executeCall['params'];

		if ($executeParams !== null) {
			return $this->validateExecuteWithArray(
				$placeholders,
				$executeParams,
				$executeLine,
			);
		}

		return $this->validateLocalExecuteWithBindings(
			$placeholders,
			$boundParams,
			$executeLine,
		);
	}

	/**
	 * Validate execute() for local variable with bindValue/bindParam
	 *
	 * @param array<string> $placeholders
	 * @param array<string> $boundParams
	 * @return array<\PHPStan\Rules\RuleError>
	 */
	private function validateLocalExecuteWithBindings(
		array $placeholders,
		array $boundParams,
		int $executeLine,
	): array {
		$errors = [];
		$missing = array_diff($placeholders, $boundParams);
		$extra = array_diff($boundParams, $placeholders);

		foreach ($missing as $param) {
			$errors[] = RuleErrorBuilder::message(sprintf(
				'Missing bindValue/bindParam for :%s',
				$param,
			))
				->line($executeLine)
				->identifier('pdoSql.missingBinding')
				->build();
		}

		foreach ($extra as $param) {
			$errors[] = RuleErrorBuilder::message(sprintf(
				'Parameter :%s is bound but not used',
				$param,
			))
				->line($executeLine)
				->identifier('pdoSql.extraBinding')
				->build();
		}

		return $errors;
	}

	/**
	 * Extract property preparations like: $this->query = $db->prepare("...")
	 * Now supports both direct strings and variables
	 *
	 * @return array<string, array{placeholders: array<string>, line: int, sql: string}>
	 */
	private function extractPropertyPreparations(Class_ $class): array
	{
		$preparations = [];

		foreach ($class->getMethods() as $classMethod) {
			// First, extract SQL variables in this method
			$sqlVariables = $this->extractSqlVariablesFromMethod($classMethod);

			foreach ($classMethod->getStmts() ?? [] as $stmt) {
				if (
					$stmt instanceof Node\Stmt\Expression
					&& $stmt->expr instanceof Assign
				) {
					$assign = $stmt->expr;

					// Check if left side is a property fetch ($this->something)
					if (!$assign->var instanceof PropertyFetch) {
						continue;
					}

					$propertyFetch = $assign->var;
					if (!$propertyFetch->var instanceof Variable) {
						continue;
					}

					if ($propertyFetch->var->name !== 'this') {
						continue;
					}

					if (!$propertyFetch->name instanceof Node\Identifier) {
						continue;
					}

					$propertyName = '$this->' . $propertyFetch->name->toString();

					// Check if right side is a prepare() call
					if (!$assign->expr instanceof MethodCall) {
						continue;
					}

					$methodCall = $assign->expr;
					if (!$methodCall->name instanceof Node\Identifier) {
						continue;
					}

					if ($methodCall->name->toString() !== 'prepare') {
						continue;
					}

					// Extract SQL string
					if ($methodCall->getArgs() === []) {
						continue;
					}

					$firstArg = $methodCall->getArgs()[0]->value;
					$sql = null;

					// Case 1: Direct string literal
					if ($firstArg instanceof String_) {
						$sql = $firstArg->value;
					} elseif ($firstArg instanceof Variable && is_string($firstArg->name)) { // Case 2: Variable reference
						$varName = $firstArg->name;
						if (isset($sqlVariables[$varName])) {
							$sql = $sqlVariables[$varName];
						}
					} elseif ($firstArg instanceof Encapsed) { // Case 3: Interpolated string (e.g., "SELECT $col FROM ...")
						$placeholders = $this->extractPlaceholdersFromEncapsedString($firstArg);
						// Use a placeholder SQL for line reference purposes
						$sql = '[interpolated string]';

						// Always add to preparations, even if no placeholders
						// This allows us to detect extra parameters in execute()
						$preparations[$propertyName] = [
							'placeholders' => $placeholders,
							'line' => $stmt->getStartLine(),
							'sql' => $sql,
						];
						continue;
					}

					if ($sql !== null) {
						$placeholders = $this->extractPlaceholders($sql);

						// Always add to preparations, even if no placeholders
						// This allows us to detect extra parameters in execute()
						$preparations[$propertyName] = [
							'placeholders' => $placeholders,
							'line' => $stmt->getStartLine(),
							'sql' => $sql,
						];
					}
				}
			}
		}

		return $preparations;
	}

	/**
	 * Extract property bindings like: $this->query->bindValue(':param', $value)
	 *
	 * @return array<string, array{params: array<string>, locations: array<string, int>}> Property name => [params, locations]
	 */
	private function extractPropertyBindings(Class_ $class): array
	{
		$bindings = [];

		foreach ($class->getMethods() as $classMethod) {
			$bindCalls = $this->findBindCallsInNode($classMethod);

			foreach ($bindCalls as $bindCall) {
				$propertyName = $bindCall['property'];
				if (!isset($bindings[$propertyName])) {
					$bindings[$propertyName] = ['params' => [], 'locations' => []];
				}

				$bindings[$propertyName]['params'][] = $bindCall['param'];
				$bindings[$propertyName]['locations'][$bindCall['param']] =
					$bindCall['line'];
			}
		}

		// Remove duplicates from params
		foreach ($bindings as $propertyName => $info) {
			$bindings[$propertyName]['params'] = array_unique($info['params']);
		}

		return $bindings;
	}

	/**
	 * Recursively find all bindValue/bindParam calls on properties in a node
	 *
	 * @return array<array{property: string, param: string, line: int}>
	 */
	private function findBindCallsInNode(Node $node): array
	{
		$results = [];

		$bindCall = $this->extractBindCallIfPresent($node);
		if ($bindCall !== null) {
			$results[] = $bindCall;
		}

		$childResults = $this->findBindCallsInChildNodes($node);
		return array_merge($results, $childResults);
	}

	/**
	 * Extract bind call information if node is a bindValue/bindParam call
	 *
	 * @return array{property: string, param: string, line: int}|null
	 */
	private function extractBindCallIfPresent(Node $node): null|array
	{
		if (!$this->isBindMethodCall($node)) {
			return null;
		}

		/** @var MethodCall $node */
		$propertyFetch = $node->var;
		if (!$this->isThisPropertyFetch($propertyFetch)) {
			return null;
		}

		/** @var PropertyFetch $propertyFetch */
		/** @var Node\Identifier $name */
		$name = $propertyFetch->name;
		$propertyName = '$this->' . $name->toString();

		if ($node->getArgs() === []) {
			return null;
		}

		$firstArg = $node->getArgs()[0]->value;
		if (!$firstArg instanceof String_) {
			return null;
		}

		$paramName = ltrim($firstArg->value, ':');

		return [
			'property' => $propertyName,
			'param' => $paramName,
			'line' => $node->getStartLine(),
		];
	}

	/**
	 * Check if node is a bindValue/bindParam method call on a property
	 */
	private function isBindMethodCall(Node $node): bool
	{
		return (
			$node instanceof MethodCall
			&& $node->name instanceof Node\Identifier
			&& in_array($node->name->toString(), ['bindValue', 'bindParam'])
			&& $node->var instanceof PropertyFetch
		);
	}

	/**
	 * Check if property fetch is $this->property
	 */
	private function isThisPropertyFetch(Node\Expr $expr): bool
	{
		return (
			$expr instanceof PropertyFetch
			&& $expr->var instanceof Variable
			&& $expr->var->name === 'this'
			&& $expr->name instanceof Node\Identifier
		);
	}

	/**
	 * Find bind calls in all child nodes
	 *
	 * @return array<array{property: string, param: string, line: int}>
	 */
	private function findBindCallsInChildNodes(Node $node): array
	{
		$results = [];

		foreach ($node->getSubNodeNames() as $subNodeName) {
			$subNode = $node->{$subNodeName};

			if ($subNode instanceof Node) {
				$results = array_merge($results, $this->findBindCallsInNode($subNode));
			} elseif (is_array($subNode)) {
				foreach ($subNode as $subNodeItem) {
					if ($subNodeItem instanceof Node) {
						$results = array_merge(
							$results,
							$this->findBindCallsInNode($subNodeItem),
						);
					}
				}
			}
		}

		return $results;
	}

	/**
	 * Extract execute() call locations and their parameters
	 *
	 * @return array<string, array<array{line: int, params: array<array{name: string, line: int}>|null}>> Property name => [execute calls with line and params]
	 */
	private function extractExecuteLocations(Class_ $class): array
	{
		$locations = [];

		foreach ($class->getMethods() as $classMethod) {
			$executeCalls = $this->findExecuteCallsInNode($classMethod);

			foreach ($executeCalls as $executeCall) {
				$propertyName = $executeCall['property'];
				if (!isset($locations[$propertyName])) {
					$locations[$propertyName] = [];
				}

				$locations[$propertyName][] = [
					'line' => $executeCall['line'],
					'params' => $executeCall['params'],
				];
			}
		}

		return $locations;
	}

	/**
	 * Recursively find all execute() calls on properties in a node
	 *
	 * @return array<array{property: string, line: int, params: array<array{name: string, line: int}>|null}>
	 */
	private function findExecuteCallsInNode(Node $node): array
	{
		$results = [];

		$executeCall = $this->extractExecuteCallIfPresent($node);
		if ($executeCall !== null) {
			$results[] = $executeCall;
		}

		$childResults = $this->findExecuteCallsInChildNodes($node);
		return array_merge($results, $childResults);
	}

	/**
	 * Extract execute call information if node is an execute() call
	 *
	 * @return array{property: string, line: int, params: array<array{name: string, line: int}>|null}|null
	 */
	private function extractExecuteCallIfPresent(Node $node): null|array
	{
		if (!$this->isExecuteMethodCall($node)) {
			return null;
		}

		/** @var MethodCall $node */
		$propertyFetch = $node->var;
		if (!$this->isThisPropertyFetch($propertyFetch)) {
			return null;
		}

		/** @var PropertyFetch $propertyFetch */
		/** @var Node\Identifier $name */
		$name = $propertyFetch->name;
		$propertyName = '$this->' . $name->toString();

		$params = $this->extractExecuteArrayParams($node);

		return [
			'property' => $propertyName,
			'line' => $node->getStartLine(),
			'params' => $params,
		];
	}

	/**
	 * Check if node is an execute() method call on a property
	 */
	private function isExecuteMethodCall(Node $node): bool
	{
		return (
			$node instanceof MethodCall
			&& $node->name instanceof Node\Identifier
			&& $node->name->toString() === 'execute'
			&& $node->var instanceof PropertyFetch
		);
	}

	/**
	 * Extract parameters from execute() array argument with their line numbers
	 *
	 * @return array<array{name: string, line: int}>|null
	 */
	private function extractExecuteArrayParams(MethodCall $methodCall): null|array
	{
		if ($methodCall->getArgs() === []) {
			return null;
		}

		$firstArg = $methodCall->getArgs()[0]->value;
		if (!$firstArg instanceof Node\Expr\Array_) {
			return null;
		}

		$params = [];
		foreach ($firstArg->items as $item) {
			if (!$item instanceof Node\Expr\ArrayItem) {
				continue;
			}

			if ($item->key instanceof String_) {
				$params[] = [
					'name' => ltrim($item->key->value, ':'),
					'line' => $item->key->getStartLine(),
				];
			}
		}

		return $params;
	}

	/**
	 * Find execute calls in all child nodes
	 *
	 * @return array<array{property: string, line: int, params: array<array{name: string, line: int}>|null}>
	 */
	private function findExecuteCallsInChildNodes(Node $node): array
	{
		$results = [];

		foreach ($node->getSubNodeNames() as $subNodeName) {
			$subNode = $node->{$subNodeName};

			if ($subNode instanceof Node) {
				$results = array_merge($results, $this->findExecuteCallsInNode($subNode));
			} elseif (is_array($subNode)) {
				foreach ($subNode as $subNodeItem) {
					if ($subNodeItem instanceof Node) {
						$results = array_merge(
							$results,
							$this->findExecuteCallsInNode($subNodeItem),
						);
					}
				}
			}
		}

		return $results;
	}

	/**
	 * Extract placeholders from SQL query
	 *
	 * @return array<string>
	 */
	private function extractPlaceholders(string $sql): array
	{
		$placeholders = [];

		// Match :placeholder_name pattern
		$matchCount = preg_match_all('/:([a-zA-Z_]\w*)/', $sql, $matches);
		if ($matchCount !== false && $matchCount > 0) {
			$placeholders = array_unique($matches[1]);
		}

		return array_values($placeholders);
	}

	/**
	 * Extract prepare() statements for local variables in a method
	 * Now supports both direct strings and variables
	 *
	 * @return array<array{var: string, sql: string, line: int, placeholders: array<string>}>
	 */
	private function extractLocalVariablePreparations(ClassMethod $classMethod): array
	{
		$preparations = [];

		// First, extract SQL variables in this method
		$sqlVariables = $this->extractSqlVariablesFromMethod($classMethod);

		foreach ($classMethod->getStmts() ?? [] as $stmt) {
			if ($stmt instanceof Node\Stmt\Expression && $stmt->expr instanceof Assign) {
				$assign = $stmt->expr;

				// Skip property assignments ($this->query)
				if ($assign->var instanceof PropertyFetch) {
					continue;
				}

				// Only process local variables
				if (!$assign->var instanceof Variable) {
					continue;
				}

				if (!is_string($assign->var->name)) {
					continue;
				}

				// Check if right side is prepare() call
				if (!$assign->expr instanceof MethodCall) {
					continue;
				}

				$methodCall = $assign->expr;
				if (!$methodCall->name instanceof Node\Identifier) {
					continue;
				}

				if ($methodCall->name->toString() !== 'prepare') {
					continue;
				}

				if ($methodCall->getArgs() === []) {
					continue;
				}

				$firstArg = $methodCall->getArgs()[0]->value;
				$sql = null;

				// Case 1: Direct string literal
				if ($firstArg instanceof String_) {
					$sql = $firstArg->value;
				} elseif ($firstArg instanceof Variable && is_string($firstArg->name)) { // Case 2: Variable reference
					$varName = $firstArg->name;
					if (isset($sqlVariables[$varName])) {
						$sql = $sqlVariables[$varName];
					}
				} elseif ($firstArg instanceof Encapsed) { // Case 3: Interpolated string (e.g., "SELECT $col FROM ...")
					$placeholders = $this->extractPlaceholdersFromEncapsedString($firstArg);
					// Use a placeholder SQL for line reference purposes
					$sql = '[interpolated string]';

					// Always add to preparations, even if no placeholders
					// This allows us to detect extra parameters in execute()
					$preparations[] = [
						'var' => '$' . $assign->var->name,
						'sql' => $sql,
						'line' => $stmt->getStartLine(),
						'placeholders' => $placeholders,
					];
					continue;
				}

				if ($sql !== null) {
					$placeholders = $this->extractPlaceholders($sql);

					// Always add to preparations, even if no placeholders
					// This allows us to detect extra parameters in execute()
					$preparations[] = [
						'var' => '$' . $assign->var->name,
						'sql' => $sql,
						'line' => $stmt->getStartLine(),
						'placeholders' => $placeholders,
					];
				}
			}
		}

		return $preparations;
	}

	/**
	 * Extract execute() calls for a local variable
	 *
	 * @return array<array{line: int, params: array<array{name: string, line: int}>|null}>
	 */
	private function extractLocalVariableExecuteCalls(
		ClassMethod $classMethod,
		string $varName,
	): array {
		$executeCalls = [];

		foreach ($classMethod->getStmts() ?? [] as $stmt) {
			if (
				$stmt instanceof Node\Stmt\Expression
				&& $stmt->expr instanceof MethodCall
			) {
				$methodCall = $stmt->expr;
				// Check if it's execute()
				if (!$methodCall->name instanceof Node\Identifier) {
					continue;
				}

				if ($methodCall->name->toString() !== 'execute') {
					continue;
				}

				// Check if called on our variable
				if (!$methodCall->var instanceof Variable) {
					continue;
				}

				if (!is_string($methodCall->var->name)) {
					continue;
				}

				if ('$' . $methodCall->var->name !== $varName) {
					continue;
				}

				// Extract parameters with line numbers using shared method
				$params = $this->extractExecuteArrayParams($methodCall);

				$executeCalls[] = [
					'line' => $stmt->getStartLine(),
					'params' => $params,
				];
			}
		}

		return $executeCalls;
	}

	/**
	 * Extract bindValue/bindParam calls for a local variable
	 *
	 * @return array<string>
	 */
	private function extractLocalVariableBindings(
		ClassMethod $classMethod,
		string $varName,
	): array {
		$bindings = [];

		foreach ($classMethod->getStmts() ?? [] as $stmt) {
			if (
				$stmt instanceof Node\Stmt\Expression
				&& $stmt->expr instanceof MethodCall
			) {
				$methodCall = $stmt->expr;

				// Check if it's bindValue or bindParam
				if (!$methodCall->name instanceof Node\Identifier) {
					continue;
				}

				$methodName = $methodCall->name->toString();
				if ($methodName !== 'bindValue' && $methodName !== 'bindParam') {
					continue;
				}

				// Check if called on our variable
				if (!$methodCall->var instanceof Variable) {
					continue;
				}

				if (!is_string($methodCall->var->name)) {
					continue;
				}

				if ('$' . $methodCall->var->name !== $varName) {
					continue;
				}

				// Extract parameter name
				if ($methodCall->getArgs() === []) {
					continue;
				}

				$firstArg = $methodCall->getArgs()[0]->value;
				if (!$firstArg instanceof String_) {
					continue;
				}

				$paramName = ltrim($firstArg->value, ':');
				$bindings[] = $paramName;
			}
		}

		return array_unique($bindings);
	}

	/**
	 * Extract SQL strings assigned to variables in a method
	 * Optimized: Only processes string assignments
	 *
	 * @return array<string, string> Variable name => SQL string
	 */
	private function extractSqlVariablesFromMethod(ClassMethod $classMethod): array
	{
		$sqlVariables = [];
		$stmts = $classMethod->getStmts();

		// Early bailout if method is empty
		if ($stmts === null || count($stmts) === 0) {
			return [];
		}

		foreach ($stmts as $stmt) {
			// Skip non-assignment statements
			if (
				!($stmt instanceof Node\Stmt\Expression && $stmt->expr instanceof Assign)
			) {
				continue;
			}

			$assign = $stmt->expr;

			// Check if left side is a simple variable
			if (!($assign->var instanceof Variable && is_string($assign->var->name))) {
				continue;
			}

			// Check if right side is a string
			if (!$assign->expr instanceof String_) {
				continue;
			}

			$sql = $assign->expr->value;

			// Simple heuristic: if it contains SQL keywords, consider it SQL
			if ($this->looksLikeSQL($sql)) {
				$varName = $assign->var->name;
				$sqlVariables[$varName] = $sql;
			}
		}

		return $sqlVariables;
	}

	/**
	 * Simple heuristic to detect if a string looks like SQL
	 */
	private function looksLikeSQL(string $str): bool
	{
		$sqlKeywords = [
			'SELECT',
			'INSERT',
			'UPDATE',
			'DELETE',
			'CREATE',
			'DROP',
			'ALTER',
			'REPLACE',
		];
		$upperStr = strtoupper(trim($str));

		foreach ($sqlKeywords as $sqlKeyword) {
			$keywordPos = strpos($upperStr, $sqlKeyword);
			if ($keywordPos !== false && $keywordPos === 0) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Extract placeholders from an encapsed (interpolated) string
	 * Example: "SELECT $col FROM users WHERE id = :id AND name = :name"
	 * This extracts placeholders from the literal parts of the string
	 *
	 * @return array<string>
	 */
	private function extractPlaceholdersFromEncapsedString(Encapsed $encapsed): array
	{
		$placeholders = [];

		foreach ($encapsed->parts as $part) {
			// Only process literal string parts
			if ($part instanceof EncapsedStringPart) {
				$literalPart = $part->value;
				$partPlaceholders = $this->extractPlaceholders($literalPart);
				$placeholders = array_merge($placeholders, $partPlaceholders);
			}
		}

		return array_values(array_unique($placeholders));
	}
}
