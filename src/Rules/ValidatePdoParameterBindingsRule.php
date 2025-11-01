<?php declare(strict_types=1);

namespace Pierresh\PhpStanPdoMysql\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Scalar\String_;
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
		$errors = [];

		// Extract all property preparations (e.g., $this->query = $db->prepare(...))
		$propertyPreparations = $this->extractPropertyPreparations($class);

		if (count($propertyPreparations) === 0) {
			return [];
		}

		// Extract all property bindings across all methods
		$propertyBindings = $this->extractPropertyBindings($class);

		// Extract execute() calls with their parameters
		$executeCalls = $this->extractExecuteLocations($class);

		// Validate each property
		foreach ($propertyPreparations as $propertyName => $info) {
			$placeholders = $info['placeholders'];
			$prepareLine = $info['line'];

			// Get all execute() calls for this property
			$executes = $executeCalls[$propertyName] ?? [];

			// If no execute() calls found, skip validation
			if (count($executes) === 0) {
				continue;
			}

			// Validate each execute() call separately
			foreach ($executes as $executeInfo) {
				$executeLine = $executeInfo['line'];
				$executeParams = $executeInfo['params'];

				// If execute() is called with an array, validate only those parameters
				// and ignore any bindValue/bindParam calls
				if ($executeParams !== null) {
					// Check for missing parameters
					$missing = array_diff($placeholders, $executeParams);
					// Check for extra parameters
					$extra = array_diff($executeParams, $placeholders);

					foreach ($missing as $param) {
						$errors[] = RuleErrorBuilder::message(
							sprintf(
								'Missing parameter :%s in execute() array - SQL query (line %d) expects this parameter',
								$param,
								$prepareLine
							)
						)->line($executeLine)->build();
					}

					foreach ($extra as $param) {
						$errors[] = RuleErrorBuilder::message(
							sprintf(
								'Parameter :%s in execute() array is not used in SQL query (line %d)',
								$param,
								$prepareLine
							)
						)->line($executeLine)->build();
					}
				} else {
					// execute() called without parameters, validate using bindValue/bindParam
					$boundParams = $propertyBindings[$propertyName]['params'] ?? [];

					// Check for missing bindings
					$missing = array_diff($placeholders, $boundParams);
					// Check for extra bindings
					$extra = array_diff($boundParams, $placeholders);

					foreach ($missing as $param) {
						$errors[] = RuleErrorBuilder::message(
							sprintf(
								'Missing binding for :%s - SQL query (line %d) expects this parameter but no bindValue/bindParam found before execute()',
								$param,
								$prepareLine
							)
						)->line($executeLine)->build();
					}

					// For extra bindings, report at the binding location
					if (isset($propertyBindings[$propertyName]['locations'])) {
						foreach ($extra as $param) {
							$bindingLine = $propertyBindings[$propertyName]['locations'][$param] ?? $executeLine;
							$errors[] = RuleErrorBuilder::message(
								sprintf(
									'Parameter :%s is bound but not used in SQL query (line %d)',
									$param,
									$prepareLine
								)
							)->line($bindingLine)->build();
						}
					}
				}
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

		foreach ($class->getMethods() as $method) {
			$errors = array_merge($errors, $this->validateMethodLocalVariables($method));
		}

		return $errors;
	}

	/**
	 * Validate local variables within a single method
	 *
	 * @return array<\PHPStan\Rules\RuleError>
	 */
	private function validateMethodLocalVariables(ClassMethod $method): array
	{
		$errors = [];

		// Extract all prepare() statements for local variables
		$preparations = $this->extractLocalVariablePreparations($method);

		foreach ($preparations as $prep) {
			$varName = $prep['var'];
			$placeholders = $prep['placeholders'];
			$prepareLine = $prep['line'];

			// Find execute() calls for this variable
			$executeCalls = $this->extractLocalVariableExecuteCalls($method, $varName);

			// Extract bindValue/bindParam calls
			$boundParams = $this->extractLocalVariableBindings($method, $varName);

			// If no execute calls found, skip validation
			if (count($executeCalls) === 0) {
				continue;
			}

			// Validate each execute() call
			foreach ($executeCalls as $executeCall) {
				$executeLine = $executeCall['line'];
				$executeParams = $executeCall['params'];

				// If execute() is called with an array, validate only those parameters
				if ($executeParams !== null) {
					$missing = array_diff($placeholders, $executeParams);
					$extra = array_diff($executeParams, $placeholders);

					foreach ($missing as $param) {
						$errors[] = RuleErrorBuilder::message(
							sprintf(
								'Missing parameter :%s in execute() array - SQL query (line %d) expects this parameter',
								$param,
								$prepareLine
							)
						)->line($executeLine)->build();
					}

					foreach ($extra as $param) {
						$errors[] = RuleErrorBuilder::message(
							sprintf(
								'Parameter :%s in execute() array is not used in SQL query (line %d)',
								$param,
								$prepareLine
							)
						)->line($executeLine)->build();
					}
				} else {
					// execute() called without array, validate using bindValue/bindParam
					$missing = array_diff($placeholders, $boundParams);
					$extra = array_diff($boundParams, $placeholders);

					foreach ($missing as $param) {
						$errors[] = RuleErrorBuilder::message(
							sprintf(
								'Missing binding for :%s - SQL query (line %d) expects this parameter but no bindValue/bindParam found before execute()',
								$param,
								$prepareLine
							)
						)->line($executeLine)->build();
					}

					foreach ($extra as $param) {
						$errors[] = RuleErrorBuilder::message(
							sprintf(
								'Parameter :%s is bound but not used in SQL query (line %d)',
								$param,
								$prepareLine
							)
						)->line($executeLine)->build();
					}
				}
			}
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

		foreach ($class->getMethods() as $method) {
			// First, extract SQL variables in this method
			$sqlVariables = $this->extractSqlVariablesFromMethod($method);

			foreach ($method->getStmts() ?? [] as $stmt) {
				if ($stmt instanceof Node\Stmt\Expression && $stmt->expr instanceof Assign) {
					$assign = $stmt->expr;

					// Check if left side is a property fetch ($this->something)
					if (!$assign->var instanceof PropertyFetch) {
						continue;
					}

					$propertyFetch = $assign->var;
					if (!$propertyFetch->var instanceof Variable || $propertyFetch->var->name !== 'this') {
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
					if (!$methodCall->name instanceof Node\Identifier || $methodCall->name->toString() !== 'prepare') {
						continue;
					}

					// Extract SQL string
					if (count($methodCall->getArgs()) === 0) {
						continue;
					}

					$firstArg = $methodCall->getArgs()[0]->value;
					$sql = null;

					// Case 1: Direct string literal
					if ($firstArg instanceof String_) {
						$sql = $firstArg->value;
					}
					// Case 2: Variable reference
					elseif ($firstArg instanceof Variable && is_string($firstArg->name)) {
						$varName = $firstArg->name;
						if (isset($sqlVariables[$varName])) {
							$sql = $sqlVariables[$varName];
						}
					}

					if ($sql !== null) {
						$placeholders = $this->extractPlaceholders($sql);

						if (count($placeholders) > 0) {
							$preparations[$propertyName] = [
								'placeholders' => $placeholders,
								'line' => $stmt->getStartLine(),
								'sql' => $sql,
							];
						}
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

		foreach ($class->getMethods() as $method) {
			foreach ($method->getStmts() ?? [] as $stmt) {
				if ($stmt instanceof Node\Stmt\Expression && $stmt->expr instanceof MethodCall) {
					$methodCall = $stmt->expr;

					// Check if it's bindValue or bindParam
					if (!$methodCall->name instanceof Node\Identifier) {
						continue;
					}

					$methodName = $methodCall->name->toString();
					if ($methodName !== 'bindValue' && $methodName !== 'bindParam') {
						continue;
					}

					// Check if called on a property ($this->query->bindValue)
					if (!$methodCall->var instanceof PropertyFetch) {
						continue;
					}

					$propertyFetch = $methodCall->var;
					if (!$propertyFetch->var instanceof Variable || $propertyFetch->var->name !== 'this') {
						continue;
					}

					if (!$propertyFetch->name instanceof Node\Identifier) {
						continue;
					}

					$propertyName = '$this->' . $propertyFetch->name->toString();

					// Extract parameter name
					if (count($methodCall->getArgs()) === 0) {
						continue;
					}

					$firstArg = $methodCall->getArgs()[0]->value;
					if (!$firstArg instanceof String_) {
						continue;
					}

					$paramName = ltrim($firstArg->value, ':');

					if (!isset($bindings[$propertyName])) {
						$bindings[$propertyName] = ['params' => [], 'locations' => []];
					}

					$bindings[$propertyName]['params'][] = $paramName;
					$bindings[$propertyName]['locations'][$paramName] = $stmt->getStartLine();
				}
			}
		}

		// Remove duplicates from params
		foreach ($bindings as $propertyName => $info) {
			$bindings[$propertyName]['params'] = array_unique($info['params']);
		}

		return $bindings;
	}

	/**
	 * Extract execute() call locations and their parameters
	 *
	 * @return array<string, array<array{line: int, params: array<string>|null}>> Property name => [execute calls with line and params]
	 */
	private function extractExecuteLocations(Class_ $class): array
	{
		$locations = [];

		foreach ($class->getMethods() as $method) {
			foreach ($method->getStmts() ?? [] as $stmt) {
				if ($stmt instanceof Node\Stmt\Expression && $stmt->expr instanceof MethodCall) {
					$methodCall = $stmt->expr;

					// Check if it's execute()
					if (!$methodCall->name instanceof Node\Identifier) {
						continue;
					}

					if ($methodCall->name->toString() !== 'execute') {
						continue;
					}

					// Check if called on a property ($this->query->execute)
					if (!$methodCall->var instanceof PropertyFetch) {
						continue;
					}

					$propertyFetch = $methodCall->var;
					if (!$propertyFetch->var instanceof Variable || $propertyFetch->var->name !== 'this') {
						continue;
					}

					if (!$propertyFetch->name instanceof Node\Identifier) {
						continue;
					}

					$propertyName = '$this->' . $propertyFetch->name->toString();

					// Extract parameters if execute() is called with an array
					$params = null;
					if (count($methodCall->getArgs()) > 0) {
						$firstArg = $methodCall->getArgs()[0]->value;
						if ($firstArg instanceof Node\Expr\Array_) {
							$params = [];
							foreach ($firstArg->items as $item) {
								if ($item === null) {
									continue;
								}
								if ($item->key instanceof String_) {
									$params[] = $item->key->value;
								}
							}
						}
					}

					if (!isset($locations[$propertyName])) {
						$locations[$propertyName] = [];
					}

					$locations[$propertyName][] = [
						'line' => $stmt->getStartLine(),
						'params' => $params,
					];
				}
			}
		}

		return $locations;
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
		$matchCount = preg_match_all('/:([a-zA-Z_][a-zA-Z0-9_]*)/', $sql, $matches);
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
	private function extractLocalVariablePreparations(ClassMethod $method): array
	{
		$preparations = [];

		// First, extract SQL variables in this method
		$sqlVariables = $this->extractSqlVariablesFromMethod($method);

		foreach ($method->getStmts() ?? [] as $stmt) {
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
				if (!$methodCall->name instanceof Node\Identifier || $methodCall->name->toString() !== 'prepare') {
					continue;
				}

				if (count($methodCall->getArgs()) === 0) {
					continue;
				}

				$firstArg = $methodCall->getArgs()[0]->value;
				$sql = null;

				// Case 1: Direct string literal
				if ($firstArg instanceof String_) {
					$sql = $firstArg->value;
				}
				// Case 2: Variable reference
				elseif ($firstArg instanceof Variable && is_string($firstArg->name)) {
					$varName = $firstArg->name;
					if (isset($sqlVariables[$varName])) {
						$sql = $sqlVariables[$varName];
					}
				}

				if ($sql !== null) {
					$placeholders = $this->extractPlaceholders($sql);

					if (count($placeholders) > 0) {
						$preparations[] = [
							'var' => '$' . $assign->var->name,
							'sql' => $sql,
							'line' => $stmt->getStartLine(),
							'placeholders' => $placeholders,
						];
					}
				}
			}
		}

		return $preparations;
	}

	/**
	 * Extract execute() calls for a local variable
	 *
	 * @return array<array{line: int, params: array<string>|null}>
	 */
	private function extractLocalVariableExecuteCalls(ClassMethod $method, string $varName): array
	{
		$executeCalls = [];

		foreach ($method->getStmts() ?? [] as $stmt) {
			if ($stmt instanceof Node\Stmt\Expression && $stmt->expr instanceof MethodCall) {
				$methodCall = $stmt->expr;

				// Check if it's execute()
				if (!$methodCall->name instanceof Node\Identifier || $methodCall->name->toString() !== 'execute') {
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

				// Extract parameters if provided as array
				$params = null;
				if (count($methodCall->getArgs()) > 0) {
					$firstArg = $methodCall->getArgs()[0]->value;
					if ($firstArg instanceof Node\Expr\Array_) {
						$params = [];
						foreach ($firstArg->items as $item) {
							if ($item === null) {
								continue;
							}
							if ($item->key instanceof String_) {
								$params[] = $item->key->value;
							}
						}
					}
				}

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
	private function extractLocalVariableBindings(ClassMethod $method, string $varName): array
	{
		$bindings = [];

		foreach ($method->getStmts() ?? [] as $stmt) {
			if ($stmt instanceof Node\Stmt\Expression && $stmt->expr instanceof MethodCall) {
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
				if (count($methodCall->getArgs()) === 0) {
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
	private function extractSqlVariablesFromMethod(ClassMethod $method): array
	{
		$sqlVariables = [];
		$stmts = $method->getStmts();

		// Early bailout if method is empty
		if ($stmts === null || count($stmts) === 0) {
			return [];
		}

		foreach ($stmts as $stmt) {
			// Skip non-assignment statements
			if (!($stmt instanceof Node\Stmt\Expression && $stmt->expr instanceof Assign)) {
				continue;
			}

			$assign = $stmt->expr;

			// Check if left side is a simple variable
			if (!($assign->var instanceof Variable && is_string($assign->var->name))) {
				continue;
			}

			// Check if right side is a string
			if (!($assign->expr instanceof String_)) {
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
		$sqlKeywords = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'DROP', 'ALTER', 'REPLACE'];
		$upperStr = strtoupper(trim($str));

		foreach ($sqlKeywords as $keyword) {
			$keywordPos = strpos($upperStr, $keyword);
			if ($keywordPos !== false && $keywordPos === 0) {
				return true;
			}
		}

		return false;
	}
}
