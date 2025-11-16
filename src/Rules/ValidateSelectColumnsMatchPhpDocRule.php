<?php declare(strict_types=1);

namespace Pierresh\PhpStanPdoMysql\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * This rule validates that SELECT columns in SQL queries match PHPDoc @var object shapes.
 *
 * IMPORTANT: This rule only validates @var annotations, not @return annotations.
 * The @var annotation describes what was EXTRACTED from the database, while @return describes
 * what the method returns (which may be different).
 *
 * Patterns it validates:
 *
 * 1. @var annotation with local prepare:
 *    - $query = $db->prepare("SELECT col1, col2 FROM ...")
 *    - $item = $query->fetchObject();
 *    - /** @var object{prop1: type, prop2: type} *\/
 *    - Validates that col1, col2 match prop1, prop2
 *
 * 2. @var annotation with class property prepare (cross-method):
 *    - Constructor: $this->query = $db->prepare("SELECT col1, col2 FROM ...")
 *    - Method: $item = $this->query->fetchObject();
 *    - /** @var object{prop1: type, prop2: type} *\/
 *    - Validates that col1, col2 match prop1, prop2
 *
 * @implements Rule<Class_>
 */
class ValidateSelectColumnsMatchPhpDocRule implements Rule
{
	public function getNodeType(): string
	{
		return Class_::class;
	}

	public function processNode(Node $node, Scope $scope): array
	{
		if ($this->shouldSkipFile($scope->getFile())) {
			return [];
		}

		$typeAliases = $this->extractTypeAliases($node);
		$propertyPreparations = $this->extractPropertyPreparations($node);

		return $this->validateAllMethods($node, $propertyPreparations, $typeAliases);
	}

	/**
	 * Check if file should be skipped (e.g., migration files)
	 */
	private function shouldSkipFile(string $filePath): bool
	{
		return str_contains($filePath, '/migrations/');
	}

	/**
	 * Validate @var annotations in all class methods
	 *
	 * @param array<string, array{sql: string, line: int, var?: string}> $propertyPreparations
	 * @param array<string, array<string, string>> $typeAliases
	 * @return array<\PHPStan\Rules\RuleError>
	 */
	private function validateAllMethods(
		Class_ $class,
		array $propertyPreparations,
		array $typeAliases,
	): array {
		$errors = [];
		$seen = [];

		foreach ($class->getMethods() as $classMethod) {
			$varAnnotations = $this->extractVarAnnotations(
				$classMethod,
				$propertyPreparations,
				$typeAliases,
			);

			$methodErrors = $this->processVarAnnotations($varAnnotations, $seen);
			$errors = array_merge($errors, $methodErrors);
		}

		return $errors;
	}

	/**
	 * Process var annotations and validate them
	 *
	 * @param array<array{sql: string, sql_line: int, object_shape: array<string, string>, var_line: int, fetch_method?: string, is_array_type?: bool, doc_text?: string, method?: ClassMethod}> $varAnnotations
	 * @param array<string, bool> &$seen
	 * @return array<\PHPStan\Rules\RuleError>
	 */
	private function processVarAnnotations(
		array $varAnnotations,
		array &$seen,
	): array {
		$errors = [];

		foreach ($varAnnotations as $varAnnotation) {
			$key = $this->getAnnotationKey($varAnnotation);

			if (isset($seen[$key])) {
				continue; // Skip duplicates
			}

			$seen[$key] = true;

			// Validate fetch method matches PHPDoc type structure
			$fetchMethodError = $this->validateFetchMethod($varAnnotation);
			if ($fetchMethodError instanceof \PHPStan\Rules\RuleError) {
				$errors[] = $fetchMethodError;
				continue; // Skip column validation if type structure is wrong
			}

			// Validate false handling for fetch() and fetchObject()
			$falseHandlingError = $this->validateFalseHandling($varAnnotation);
			if ($falseHandlingError instanceof \PHPStan\Rules\RuleError) {
				$errors[] = $falseHandlingError;
			}

			// Validate columns
			$columnErrors = $this->validateSqlAgainstPhpDoc(
				$varAnnotation['sql'],
				$varAnnotation['sql_line'],
				$varAnnotation['object_shape'],
				$varAnnotation['var_line'],
			);
			$errors = array_merge($errors, $columnErrors);
		}

		return $errors;
	}

	/**
	 * Generate unique key for annotation to avoid duplicates
	 *
	 * @param array{sql: string, sql_line: int, object_shape: array<string, string>, var_line: int, fetch_method?: string, is_array_type?: bool} $varAnnotation
	 */
	private function getAnnotationKey(array $varAnnotation): string
	{
		return $varAnnotation['var_line']
		. ':'
		. $varAnnotation['sql_line']
		. ':'
		. json_encode($varAnnotation['object_shape']);
	}

	/**
	 * Validate fetch method if present
	 *
	 * @param array{sql: string, sql_line: int, object_shape: array<string, string>, var_line: int, fetch_method?: string, is_array_type?: bool} $varAnnotation
	 */
	private function validateFetchMethod(array $varAnnotation): null|\PHPStan\Rules\RuleError
	{
		if (!isset($varAnnotation['fetch_method'])) {
			return null;
		}

		return $this->validateFetchMethodMatchesPhpDocType(
			$varAnnotation['fetch_method'],
			$varAnnotation['is_array_type'] ?? false,
			$varAnnotation['sql_line'],
			$varAnnotation['var_line'],
		);
	}

	/**
	 * Validate that the fetch method matches the PHPDoc type structure
	 */
	private function validateFetchMethodMatchesPhpDocType(
		string $fetchMethod,
		bool $isArrayType,
		int $sqlLine,
		int $varLine,
	): null|\PHPStan\Rules\RuleError {
		// fetchAll() should have array<object{...}> type
		if ($fetchMethod === 'fetchAll' && !$isArrayType) {
			return RuleErrorBuilder::message(sprintf(
				'Type mismatch: fetchAll() returns array<object{...}> but PHPDoc specifies object{...} (line %d)',
				$sqlLine,
			))
				->line($varLine)
				->identifier('pdoSql.fetchTypeMismatch')
				->build();
		}

		// fetch() and fetchObject() should have object{...} type (not array)
		if (
			($fetchMethod === 'fetch' || $fetchMethod === 'fetchObject')
			&& $isArrayType
		) {
			return RuleErrorBuilder::message(sprintf(
				'Type mismatch: %s() returns object{...} but PHPDoc specifies array<object{...}> (line %d)',
				$fetchMethod,
				$sqlLine,
			))
				->line($varLine)
				->identifier('pdoSql.fetchTypeMismatch')
				->build();
		}

		return null;
	}

	/**
	 * Validate false handling for fetch() and fetchObject()
	 * These methods can return false when no results found, unless:
	 * 1. The PHPDoc includes |false union type
	 * 2. The code checks rowCount() before fetch
	 * 3. The code checks === false, !== false, or !$var after fetch
	 * 4. The @var is inside a while loop (false stops execution automatically)
	 *
	 * @param array{sql: string, sql_line: int, object_shape: array<string, string>, var_line: int, fetch_method?: string, is_array_type?: bool, doc_text?: string, method?: ClassMethod, in_while_loop?: bool} $varAnnotation
	 */
	private function validateFalseHandling(array $varAnnotation): null|\PHPStan\Rules\RuleError
	{
		// Only validate fetch() and fetchObject(), NOT fetchAll()
		if (!isset($varAnnotation['fetch_method'])) {
			return null;
		}

		$fetchMethod = $varAnnotation['fetch_method'];
		if ($fetchMethod !== 'fetch' && $fetchMethod !== 'fetchObject') {
			return null;
		}

		// Skip validation if @var is inside a while loop condition
		// In while loops, if fetch() returns false, the loop body won't execute
		if (
			isset($varAnnotation['in_while_loop'])
			&& $varAnnotation['in_while_loop']
		) {
			return null;
		}

		// Check if PHPDoc includes |false
		if (isset($varAnnotation['doc_text'])) {
			$docText = $varAnnotation['doc_text'];
			// Match @var ... |false or @var false|... (with optional spaces around |)
			if (
				preg_match('/@var\s+[^@\n]*\|\s*false/', $docText)
				|| preg_match('/@var\s+false\s*\|/', $docText)
			) {
				return null; // Has |false, no error
			}
		}

		// Check if code has false-handling nearby
		if (
			isset($varAnnotation['method'])
			&& $this->hasFalseHandlingInMethod($varAnnotation['method'])
		) {
			return null; // Has false handling, no error
		}

		// No |false and no false-handling detected
		return RuleErrorBuilder::message(sprintf(
			'Missing |false in @var type: %s() can return false when no results found. Either add |false to the type or check for false/rowCount() before using the result (line %d)',
			$fetchMethod,
			$varAnnotation['sql_line'],
		))
			->line($varAnnotation['var_line'])
			->identifier('pdoSql.missingFalseType')
			->build();
	}

	/**
	 * Check if the code has false-handling patterns in the method
	 * Optimized single-pass detection for:
	 * - rowCount() checks that throw/return before fetch
	 * - === false, !== false, or !$var checks after fetch
	 */
	private function hasFalseHandlingInMethod(ClassMethod $classMethod): bool
	{
		$statements = $classMethod->getStmts() ?? [];

		// Single pass through statements - check both patterns at once
		foreach ($statements as $statement) {
			// Fast check: is this an if statement?
			if ($statement instanceof Node\Stmt\If_) {
				// Check for rowCount() with throw/return (most specific check first)
				if ($this->isRowCountCheckWithThrowOrReturn($statement)) {
					return true;
				}

				// Check for false comparison in condition
				if ($this->hasFalseComparisonInCondition($statement->cond)) {
					return true;
				}
			}

			// Quick check for boolean not in if conditions
			if (
				$statement instanceof Node\Stmt\If_
				&& $statement->cond instanceof Node\Expr\BooleanNot
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Optimized check for rowCount() with throw/return
	 */
	private function isRowCountCheckWithThrowOrReturn(Node\Stmt\If_ $if): bool
	{
		// Quick check: does condition contain rowCount()?
		if (!$this->containsRowCountCall($if->cond)) {
			return false;
		}

		// Check if body has throw or return (only check top-level statements)
		foreach ($if->stmts as $stmt) {
			if (
				$stmt instanceof Node\Stmt\Throw_
				|| $stmt instanceof Node\Stmt\Return_
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Fast non-recursive check for rowCount() in condition
	 * Only searches one level deep for performance
	 */
	private function containsRowCountCall(Node\Expr $expr): bool
	{
		// Direct method call: $stmt->rowCount()
		if (
			$expr instanceof MethodCall
			&& $expr->name instanceof Node\Identifier
			&& $expr->name->toString() === 'rowCount'
		) {
			return true;
		}

		// Binary operation: $stmt->rowCount() === 0
		if ($expr instanceof Node\Expr\BinaryOp) {
			if (
				$expr->left instanceof MethodCall
				&& $expr->left->name instanceof Node\Identifier
				&& $expr->left->name->toString() === 'rowCount'
			) {
				return true;
			}

			if (
				$expr->right instanceof MethodCall
				&& $expr->right->name instanceof Node\Identifier
				&& $expr->right->name->toString() === 'rowCount'
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Fast check for false comparison (=== false, !== false)
	 */
	private function hasFalseComparisonInCondition(Node\Expr $expr): bool
	{
		// Check for === false or !== false comparisons
		if (
			$expr instanceof Node\Expr\BinaryOp\Identical
			|| $expr instanceof Node\Expr\BinaryOp\NotIdentical
		) {
			// Check if either side is false literal
			$leftIsFalse =
				$expr->left instanceof Node\Expr\ConstFetch
				&& $expr->left->name instanceof Node\Name
				&& $expr->left->name->toString() === 'false';

			$rightIsFalse =
				$expr->right instanceof Node\Expr\ConstFetch
				&& $expr->right->name instanceof Node\Name
				&& $expr->right->name->toString() === 'false';

			return $leftIsFalse || $rightIsFalse;
		}

		return false;
	}

	/**
	 * Extract @phpstan-type definitions from class PHPDoc
	 *
	 * @return array<string, array<string, string>> Type alias name => object shape
	 */
	private function extractTypeAliases(Class_ $class): array
	{
		$docComment = $class->getDocComment();
		if ($docComment === null) {
			return [];
		}

		$docText = $docComment->getText();

		// Try single-line format first
		$aliases = $this->extractSingleLineTypeAliases($docText);

		// Then handle multiline format
		$multilineAliases = $this->extractMultilineTypeAliases($docText);

		return array_merge($aliases, $multilineAliases);
	}

	/**
	 * Extract type aliases from single-line format
	 *
	 * @return array<string, array<string, string>>
	 */
	private function extractSingleLineTypeAliases(string $docText): array
	{
		$aliases = [];
		$lines = explode("\n", $docText);

		foreach ($lines as $line) {
			$match = $this->matchTypeAliasPattern($line);
			if ($match !== null) {
				[$aliasName, $properties] = $match;
				$aliases[$aliasName] = $properties;
			}
		}

		return $aliases;
	}

	/**
	 * Extract type aliases from multiline format
	 *
	 * @return array<string, array<string, string>>
	 */
	private function extractMultilineTypeAliases(string $docText): array
	{
		$aliases = [];

		// Remove * from doc comments for multiline parsing
		$cleanedDocText = preg_replace('/\s*\*\s*/m', ' ', $docText);
		if (!is_string($cleanedDocText)) {
			return [];
		}

		$matchCount = preg_match_all(
			'/@phpstan-type\s+(\w+)\s+object\s*\{([^}]+)\}/s',
			$cleanedDocText,
			$matches,
			PREG_SET_ORDER,
		);

		if ($matchCount === false || $matchCount === 0) {
			return [];
		}

		foreach ($matches as $match) {
			$parsed = $this->parseTypeAliasMatch($match);
			if ($parsed !== null) {
				[$aliasName, $properties] = $parsed;
				$aliases[$aliasName] = $properties;
			}
		}

		return $aliases;
	}

	/**
	 * Match and parse type alias pattern
	 *
	 * @return array{string, array<string, string>}|null [aliasName, properties]
	 */
	private function matchTypeAliasPattern(string $text): null|array
	{
		$matchCount = preg_match(
			'/@phpstan-type\s+(\w+)\s+object\s*\{([^}]+)\}/s',
			$text,
			$matches,
		);

		if ($matchCount === false || $matchCount === 0) {
			return null;
		}

		return $this->parseTypeAliasMatch($matches);
	}

	/**
	 * Parse matched type alias into name and properties
	 *
	 * @param array<int, string> $match
	 * @return array{string, array<string, string>}|null [aliasName, properties]
	 */
	private function parseTypeAliasMatch(array $match): null|array
	{
		$aliasName = $match[1];
		$shapeContent = $match[2];

		$properties = $this->parseObjectShapeProperties($shapeContent);

		if ($properties === []) {
			return null;
		}

		return [$aliasName, $properties];
	}

	/**
	 * Parse object shape properties from string
	 *
	 * @return array<string, string>
	 */
	private function parseObjectShapeProperties(string $shapeContent): array
	{
		$properties = [];
		$parts = explode(',', $shapeContent);

		foreach ($parts as $part) {
			$part = trim($part);
			$propMatchCount = preg_match('/^(\w+)\s*:\s*(.+)$/', $part, $propMatch);

			if ($propMatchCount !== false && $propMatchCount > 0) {
				$properties[$propMatch[1]] = trim($propMatch[2]);
			}
		}

		return $properties;
	}

	/**
	 * Extract property preparations like: $this->query = $db->prepare("...")
	 * Now supports both direct strings and variables
	 *
	 * @return array<string, array{sql: string, line: int, var?: string}>
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
					&& $stmt->expr instanceof Node\Expr\Assign
				) {
					$assign = $stmt->expr;

					// Check if left side is $this->property
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

					// Check if right side is prepare()
					if (!$assign->expr instanceof MethodCall) {
						continue;
					}

					$methodCall = $assign->expr;
					if (
						$methodCall->name instanceof Node\Identifier
						&& $methodCall->name->toString() === 'prepare'
						&& $methodCall->getArgs() !== []
					) {
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
						}

						if ($sql !== null) {
							$preparations[$propertyName] = [
								'sql' => $sql,
								'line' => $stmt->getStartLine(),
							];
						}
					}
				}
			}
		}

		return $preparations;
	}

	/**
	 * Extract @var annotations from method body and match them with SQL queries
	 *
	 * @param array<string, array{sql: string, line: int, var?: string}> $propertyPreparations
	 * @param array<string, array<string, string>> $typeAliases
	 * @return array<array{sql: string, sql_line: int, object_shape: array<string, string>, var_line: int, fetch_method?: string, is_array_type?: bool, doc_text?: string, method?: ClassMethod, in_while_loop?: bool}>
	 */
	private function extractVarAnnotations(
		ClassMethod $classMethod,
		array $propertyPreparations,
		array $typeAliases,
	): array {
		$annotations = [];

		// First, collect all @var annotations in this method
		$varShapes = [];
		foreach ($classMethod->getStmts() ?? [] as $stmt) {
			$this->collectVarAnnotationsRecursive($stmt, $varShapes, $typeAliases);
		}

		if (count($varShapes) === 0) {
			return [];
		}

		// Find SQL queries in this method (local prepare() or property usage)
		$sqlQueries = [];

		// Check for local prepare() calls
		$prepareStatements = $this->extractPrepareStatementsFromMethod($classMethod);
		$sqlQueries = $prepareStatements;

		// Check for property usage
		$propertiesUsed = $this->extractPropertiesUsedForFetch($classMethod);
		foreach ($propertiesUsed as $propertyUsed) {
			if (isset($propertyPreparations[$propertyUsed])) {
				$sqlQueries[] = $propertyPreparations[$propertyUsed];
			}
		}

		// Match each @var annotation with SQL queries by variable name
		foreach ($varShapes as $varShape) {
			$matchedSql = null;

			// If we know which variable is being fetched from, match by variable name
			if ($varShape['fetch_var'] !== null) {
				foreach ($sqlQueries as $sqlQuery) {
					if (
						isset($sqlQuery['var'])
						&& $sqlQuery['var'] === $varShape['fetch_var']
					) {
						$matchedSql = $sqlQuery;
						break;
					}
				}
			}

			// Fallback: if no variable match or no fetch_var, use closest SQL before @var
			if ($matchedSql === null) {
				$closestDistance = PHP_INT_MAX;
				foreach ($sqlQueries as $sqlQuery) {
					if ($sqlQuery['line'] < $varShape['line']) {
						$distance = $varShape['line'] - $sqlQuery['line'];
						if ($distance < $closestDistance) {
							$closestDistance = $distance;
							$matchedSql = $sqlQuery;
						}
					}
				}
			}

			// If we found a matching SQL query, validate it
			if ($matchedSql !== null) {
				$annotations[] = [
					'sql' => $matchedSql['sql'],
					'sql_line' => $matchedSql['line'],
					'object_shape' => $varShape['object_shape'],
					'var_line' => $varShape['line'],
					'fetch_method' => $varShape['fetch_method'] ?? null,
					'is_array_type' => $varShape['is_array_type'] ?? false,
					'doc_text' => $varShape['doc_text'] ?? null,
					'method' => $classMethod,
					'in_while_loop' => $varShape['in_while_loop'] ?? false,
				];
			}
		}

		return $annotations;
	}

	/**
	 * Recursively collect @var object{...} annotations
	 *
	 * @param array<array{line: int, object_shape: array<string, string>, fetch_var: string|null, fetch_method?: string, is_array_type?: bool, doc_text?: string, in_while_loop?: bool}> &$varShapes
	 * @param array<string, array<string, string>> $typeAliases
	 * @param array{var: string, method: string}|null $whileLoopContext Context when processing while loop body
	 */
	private function collectVarAnnotationsRecursive(
		Node $node,
		array &$varShapes,
		array $typeAliases,
		null|array $whileLoopContext = null,
	): void {
		// Special handling for while loops: while ($user = $stmt->fetch()) { /** @var ... */ ... }
		if ($node instanceof Node\Stmt\While_ && $whileLoopContext === null) {
			$whileLoopFetchInfo = $this->extractFetchInfoFromWhileCondition($node);
			if ($whileLoopFetchInfo !== []) {
				// Process the while loop body with the fetch info from the condition
				// Recurse into the while body with the context set
				foreach ($node->stmts as $stmt) {
					$this->collectVarAnnotationsRecursive(
						$stmt,
						$varShapes,
						$typeAliases,
						$whileLoopFetchInfo,
					);
				}

				// Don't recurse normally into while loops - we've handled them specially
				return;
			}
		}

		$docComment = $node->getDocComment();
		if ($docComment !== null) {
			$docText = $docComment->getText();

			// Process @var comments on statement nodes
			// When inside a while loop, accept any statement node
			// Outside while loops, only accept Expression and Return_ nodes
			$isStatementNode = $whileLoopContext !== null
				? $node instanceof Node\Stmt
				: $node instanceof Node\Stmt\Expression
				|| $node instanceof Node\Stmt\Return_;

			if ($isStatementNode) {
				// Check if the @var has array syntax: array<object{...}> or object{...}[]
				$isArrayType =
					(bool) preg_match('/@var\s+array<\s*object\s*\{/', $docText)
					|| (bool) preg_match('/@var\s+object\s*\{[^}]+\}\s*\[\]/', $docText);

				// First try to extract inline object shape: @var object{...} or @var array<object{...}> or @var object{...}[]
				$objectShape = $this->extractObjectShapeFromPhpDoc($docText, '@var');

				// If not found, check if it's a type alias: @var AliasName
				if ($objectShape === null) {
					$matchCount = preg_match('/@var\s+(\w+)/', $docText, $matches);
					if ($matchCount !== false && $matchCount > 0) {
						$typeName = $matches[1];
						if (isset($typeAliases[$typeName])) {
							$objectShape = $typeAliases[$typeName];
						}
					}
				}

				if ($objectShape !== null) {
					$varLine = $this->getVarAnnotationLine($docComment);

					// Determine fetch info based on context
					if ($whileLoopContext !== null) {
						// Inside while loop - use context from while condition
						$fetchInfo = $whileLoopContext;
						$inWhileLoop = true;
					} else {
						// Normal case - try to find fetch info from the node itself
						$fetchInfo = $this->getFetchInfoAfterComment($node);
						$inWhileLoop = false;
					}

					$varShapes[] = [
						'line' => $varLine,
						'object_shape' => $objectShape,
						'fetch_var' => $fetchInfo['var'] ?? null,
						'fetch_method' => $fetchInfo['method'] ?? null,
						'is_array_type' => $isArrayType,
						'doc_text' => $docText,
						'in_while_loop' => $inWhileLoop,
					];
				}
			}
		}

		// Recurse into child nodes (pass along the while loop context if present)
		foreach ($node->getSubNodeNames() as $subNodeName) {
			$subNode = $node->$subNodeName;

			if (is_array($subNode)) {
				foreach ($subNode as $item) {
					if ($item instanceof Node) {
						$this->collectVarAnnotationsRecursive(
							$item,
							$varShapes,
							$typeAliases,
							$whileLoopContext,
						);
					}
				}
			} elseif ($subNode instanceof Node) {
				$this->collectVarAnnotationsRecursive(
					$subNode,
					$varShapes,
					$typeAliases,
					$whileLoopContext,
				);
			}
		}
	}

	/**
	 * Extract fetch info from while loop condition
	 * Pattern: while ($user = $stmt->fetch())
	 *
	 * @return array{var: string, method: string}|array{}
	 */
	private function extractFetchInfoFromWhileCondition(Node\Stmt\While_ $while): array
	{
		// Check if condition is an assignment
		if (!$while->cond instanceof Node\Expr\Assign) {
			return [];
		}

		$assign = $while->cond;

		// Check if right side is a fetch/fetchObject/fetchAll method call
		if (!$assign->expr instanceof MethodCall) {
			return [];
		}

		$methodCall = $assign->expr;
		if (!$methodCall->name instanceof Node\Identifier) {
			return [];
		}

		$methodName = $methodCall->name->toString();
		if (!in_array($methodName, ['fetch', 'fetchObject', 'fetchAll'], true)) {
			return [];
		}

		// Get the statement variable being called on (e.g., $stmt)
		if (
			!$methodCall->var instanceof Variable
			|| !is_string($methodCall->var->name)
		) {
			return [];
		}

		return [
			'var' => $methodCall->var->name,
			'method' => $methodName,
		];
	}

	/**
	 * Get the line number of the @var annotation
	 */
	private function getVarAnnotationLine(\PhpParser\Comment\Doc $doc): int
	{
		$docStartLine = $doc->getStartLine();
		$lines = explode("\n", $doc->getText());
		$lineOffset = 0;

		foreach ($lines as $line) {
			$varPos = strpos($line, '@var');
			if ($varPos !== false) {
				return $docStartLine + $lineOffset;
			}

			$lineOffset++;
		}

		return $docStartLine;
	}

	/**
	 * Extract fetch information from a statement with a var comment
	 * Pattern: comment followed by assignment like user = stmt->fetch()
	 * Or: comment followed by return like return stmt->fetch()
	 * We want to extract "stmt" (the variable being fetched from) and the method name
	 *
	 * @return array{var?: string, method?: string}
	 */
	private function getFetchInfoAfterComment(Node $node): array
	{
		// Case 1: Assignment - /** @var ... */ $user = $stmt->fetch();
		if (
			$node instanceof Node\Stmt\Expression
			&& $node->expr instanceof Node\Expr\Assign
		) {
			$assign = $node->expr;
			// Check if right side is a method call (fetch/fetchObject/fetchAll)
			if ($assign->expr instanceof MethodCall) {
				$methodCall = $assign->expr;
				if ($methodCall->name instanceof Node\Identifier) {
					$methodName = $methodCall->name->toString();
					if (in_array($methodName, ['fetch', 'fetchObject', 'fetchAll'], true)) {
						$result = ['method' => $methodName];
						// Extract the variable being called on
						if (
							$methodCall->var instanceof Variable
							&& is_string($methodCall->var->name)
						) {
							$result['var'] = $methodCall->var->name;
						}

						return $result;
					}
				}
			}
		}

		// Case 2: Return statement - /** @var ... */ return $stmt->fetch();
		if ($node instanceof Node\Stmt\Return_ && $node->expr instanceof MethodCall) {
			$methodCall = $node->expr;
			if ($methodCall->name instanceof Node\Identifier) {
				$methodName = $methodCall->name->toString();
				if (in_array($methodName, ['fetch', 'fetchObject', 'fetchAll'], true)) {
					$result = ['method' => $methodName];
					// Extract the variable being called on
					if (
						$methodCall->var instanceof Variable
						&& is_string($methodCall->var->name)
					) {
						$result['var'] = $methodCall->var->name;
					}

					return $result;
				}
			}
		}

		return [];
	}

	/**
	 * Extract properties used in fetchObject() calls
	 *
	 * @return array<string> Property names like '$this->query'
	 */
	private function extractPropertiesUsedForFetch(ClassMethod $classMethod): array
	{
		$properties = [];

		foreach ($classMethod->getStmts() ?? [] as $stmt) {
			$this->findFetchCallsRecursive($stmt, $properties);
		}

		return array_unique($properties);
	}

	/**
	 * Recursively find fetch/fetchObject/fetchAll() calls and track properties
	 *
	 * @param array<string> &$properties
	 */
	private function findFetchCallsRecursive(Node $node, array &$properties): void
	{
		// Check if current node is fetch/fetchObject/fetchAll() call on a property
		// Check if it's called on a property
		if (
			$node instanceof MethodCall
			&& $node->name instanceof Node\Identifier
			&& in_array(
				$node->name->toString(),
				['fetch', 'fetchObject', 'fetchAll'],
				true,
			)
			&& $node->var instanceof PropertyFetch
		) {
			$propertyFetch = $node->var;
			if (
				$propertyFetch->var instanceof Variable
				&& $propertyFetch->var->name === 'this'
				&& $propertyFetch->name instanceof Node\Identifier
			) {
				$properties[] = '$this->' . $propertyFetch->name->toString();
			}
		}

		// Recursively search in child nodes
		foreach ($node->getSubNodeNames() as $subNodeName) {
			$subNode = $node->$subNodeName;

			if (is_array($subNode)) {
				foreach ($subNode as $item) {
					if ($item instanceof Node) {
						$this->findFetchCallsRecursive($item, $properties);
					}
				}
			} elseif ($subNode instanceof Node) {
				$this->findFetchCallsRecursive($subNode, $properties);
			}
		}
	}

	/**
	 * Validate SQL against PHPDoc object shape
	 *
	 * @param array<string, string> $objectShape
	 * @return array<\PHPStan\Rules\RuleError>
	 */
	private function validateSqlAgainstPhpDoc(
		string $sql,
		int $sqlLine,
		array $objectShape,
		null|int $reportLine = null,
	): array {
		$errors = [];
		$reportLine ??= $sqlLine;

		// Extract SELECT columns
		$selectColumns = $this->extractSelectColumns($sql);

		if ($selectColumns === null) {
			// Cannot extract columns - this could be:
			// 1. SELECT * (which we cannot validate statically)
			// 2. Malformed SQL
			// We silently skip validation rather than reporting an error,
			// because SELECT * is a valid pattern that simply cannot be analyzed statically
			return $errors;
		}

		// Compare columns with object shape properties
		$expectedProps = array_keys($objectShape);
		$actualColumns = $selectColumns;

		// Check for missing columns (in PHPDoc but not in SELECT)
		$missingInSelect = array_diff($expectedProps, $actualColumns);
		// Calculate extra columns for typo detection (but we won't report them as errors)
		$extraInSelect = array_diff($actualColumns, $expectedProps);
		// Check for typos
		$typos = $this->findPotentialTypos($missingInSelect, $extraInSelect);

		foreach ($missingInSelect as $prop) {
			if (isset($typos[$prop])) {
				$errors[] = RuleErrorBuilder::message(sprintf(
					'SELECT column mismatch: PHPDoc expects property "%s" but SELECT (line %d) has "%s" - possible typo?',
					$prop,
					$sqlLine,
					$typos[$prop],
				))
					->line($reportLine)
					->identifier('pdoSql.columnMismatch')
					->build();
			} else {
				$errors[] = RuleErrorBuilder::message(sprintf(
					'SELECT column missing: PHPDoc expects property "%s" but it is not in the SELECT query (line %d)',
					$prop,
					$sqlLine,
				))
					->line($reportLine)
					->identifier('pdoSql.columnMissing')
					->build();
			}
		}

		return $errors;
	}

	/**
	 * Extract object shape from @return or @var object{...} annotation
	 * Also handles array<object{...}> and object{...}[] syntax
	 *
	 * @return array<string, string>|null Property name => type
	 */
	private function extractObjectShapeFromPhpDoc(
		string $docComment,
		string $annotation = '@return',
	): null|array {
		// Clean up doc comment: remove leading asterisks and normalize whitespace
		// This handles multiline PHPDoc comments like:
		// /**
		//  * @var object{
		//  *   id: int,
		//  *   name: string
		//  * }
		//  */
		$cleanedComment = preg_replace('/^\s*\*\s*/m', ' ', $docComment);
		if (!is_string($cleanedComment)) {
			return null;
		}

		// First try to match array<object{...}> pattern (with s modifier for multiline)
		$arrayPattern =
			'/'
			. preg_quote($annotation, '/')
			. '\s+array<\s*object\s*\{([^}]+)\}\s*>/s';
		$matchCount = preg_match($arrayPattern, $cleanedComment, $matches);

		// If not found, try object{...}[] pattern (suffix syntax)
		if ($matchCount === false || $matchCount === 0) {
			$suffixPattern =
				'/' . preg_quote($annotation, '/') . '\s+object\s*\{([^}]+)\}\s*\[\]/s';
			$matchCount = preg_match($suffixPattern, $cleanedComment, $matches);
		}

		// If not found, try simple object{...} pattern
		if ($matchCount === false || $matchCount === 0) {
			$pattern = '/' . preg_quote($annotation, '/') . '\s+object\s*\{([^}]+)\}/s';
			$matchCount = preg_match($pattern, $cleanedComment, $matches);
			if ($matchCount === false || $matchCount === 0) {
				return null;
			}
		}

		$shapeContent = $matches[1];
		$properties = [];

		// Split by comma (basic parsing - doesn't handle nested types)
		$parts = explode(',', $shapeContent);

		foreach ($parts as $part) {
			$part = trim($part);
			// Match "propertyName: type"
			$propMatchCount = preg_match('/^(\w+)\s*:\s*(.+)$/', $part, $propMatch);
			if ($propMatchCount !== false && $propMatchCount > 0) {
				$properties[$propMatch[1]] = trim($propMatch[2]);
			}
		}

		return $properties !== [] ? $properties : null;
	}

	/**
	 * Extract prepare() statements from the method
	 * Now supports both direct strings and variables
	 *
	 * @return array<array{sql: string, line: int, var?: string}>
	 */
	private function extractPrepareStatementsFromMethod(ClassMethod $classMethod): array
	{
		$statements = [];

		// First, extract SQL variables in this method
		$sqlVariables = $this->extractSqlVariablesFromMethod($classMethod);

		foreach ($classMethod->getStmts() ?? [] as $stmt) {
			$this->findPrepareCallsRecursive($stmt, $statements, $sqlVariables);
		}

		return $statements;
	}

	/**
	 * Recursively find prepare() calls in statements
	 *
	 * @param array<array{sql: string, line: int, var: string|null}> &$statements
	 * @param array<string, string> $sqlVariables
	 */
	private function findPrepareCallsRecursive(
		Node $node,
		array &$statements,
		array $sqlVariables,
	): void {
		// Check if current node is a prepare() call
		if (
			$node instanceof Node\Stmt\Expression
			&& $node->expr instanceof MethodCall
		) {
			$methodCall = $node->expr;

			if (
				$methodCall->name instanceof Node\Identifier
				&& $methodCall->name->toString() === 'prepare'
				&& $methodCall->getArgs() !== []
			) {
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
				}

				if ($sql !== null) {
					$statements[] = [
						'sql' => $sql,
						'line' => $node->getStartLine(),
						'var' => null, // No variable assignment
					];
				}
			}
		}

		// Also check assignments: $var = $db->prepare(...)
		if (
			$node instanceof Node\Stmt\Expression
			&& $node->expr instanceof Node\Expr\Assign
		) {
			$assign = $node->expr;
			if ($assign->expr instanceof MethodCall) {
				$methodCall = $assign->expr;

				if (
					$methodCall->name instanceof Node\Identifier
					&& $methodCall->name->toString() === 'prepare'
					&& $methodCall->getArgs() !== []
				) {
					$firstArg = $methodCall->getArgs()[0]->value;
					$sql = null;
					$assignedVar = null;

					// Capture the variable name being assigned to
					if ($assign->var instanceof Variable && is_string($assign->var->name)) {
						$assignedVar = $assign->var->name;
					}

					// Case 1: Direct string literal
					if ($firstArg instanceof String_) {
						$sql = $firstArg->value;
					} elseif ($firstArg instanceof Variable && is_string($firstArg->name)) { // Case 2: Variable reference
						$varName = $firstArg->name;
						if (isset($sqlVariables[$varName])) {
							$sql = $sqlVariables[$varName];
						}
					}

					if ($sql !== null) {
						$statements[] = [
							'sql' => $sql,
							'line' => $node->getStartLine(),
							'var' => $assignedVar, // Track which variable this SQL is assigned to
						];
					}
				}
			}
		}

		// Recursively search in child nodes
		foreach ($node->getSubNodeNames() as $subNodeName) {
			$subNode = $node->$subNodeName;

			if (is_array($subNode)) {
				foreach ($subNode as $item) {
					if ($item instanceof Node) {
						$this->findPrepareCallsRecursive($item, $statements, $sqlVariables);
					}
				}
			} elseif ($subNode instanceof Node) {
				$this->findPrepareCallsRecursive($subNode, $statements, $sqlVariables);
			}
		}
	}

	/**
	 * Extract SELECT columns from SQL query
	 *
	 * @return array<string>|null Column names or null if parsing fails
	 */
	private function extractSelectColumns(string $sql): null|array
	{
		// Remove comments and normalize whitespace
		$sql = (string) preg_replace('/--.*$/m', '', $sql);
		$sql = (string) preg_replace('/\/\*.*?\*\//s', '', $sql);
		$sql = (string) preg_replace('/\s+/', ' ', trim($sql));

		// Match SELECT ... FROM pattern
		$selectMatchCount = preg_match(
			'/^\s*SELECT\s+(.*?)\s+FROM\s+/i',
			$sql,
			$matches,
		);
		if ($selectMatchCount === false || $selectMatchCount === 0) {
			return null;
		}

		$selectPart = $matches[1];

		// Handle SELECT * or SELECT table.*
		$trimmedSelect = trim($selectPart);
		if ($trimmedSelect === '*' || preg_match('/\w+\.\*/', $trimmedSelect)) {
			return null; // Can't validate SELECT * or table.*
		}

		// Split by comma
		$columns = [];
		$parts = array_map('trim', explode(',', $selectPart));

		foreach ($parts as $part) {
			// Handle aliases: "column AS alias" or "column alias"
			$asMatchCount = preg_match('/\s+AS\s+(\w+)$/i', $part, $aliasMatch);
			if ($asMatchCount !== false && $asMatchCount > 0) {
				$columns[] = $aliasMatch[1];
			} else {
				$spaceMatchCount = preg_match('/[\w.]+\s+(\w+)$/i', $part, $aliasMatch);
				if ($spaceMatchCount !== false && $spaceMatchCount > 0) {
					// Column with space-separated alias
					$potentialAlias = $aliasMatch[1];
					$keywords = [
						'FROM',
						'WHERE',
						'JOIN',
						'LEFT',
						'RIGHT',
						'INNER',
						'OUTER',
						'ON',
					];
					if (!in_array(strtoupper($potentialAlias), $keywords, true)) {
						$columns[] = $potentialAlias;
					}
				} else {
					// Simple column name or table.column
					$colMatchCount = preg_match('/(?:^|\.)(\w+)$/', $part, $colMatch);
					if ($colMatchCount !== false && $colMatchCount > 0) {
						$columns[] = $colMatch[1];
					}
				}
			}
		}

		return $columns !== [] ? array_values(array_unique($columns)) : null;
	}

	/**
	 * Find potential typos using Levenshtein distance
	 *
	 * @param array<string> $missing Properties in PHPDoc but not in SELECT
	 * @param array<string> $extra Columns in SELECT but not in PHPDoc
	 * @return array<string, string> Missing property => suggested column
	 */
	private function findPotentialTypos(array $missing, array $extra): array
	{
		$typos = [];

		foreach ($missing as $missingProp) {
			$bestMatch = null;
			$bestDistance = PHP_INT_MAX;

			foreach ($extra as $extraCol) {
				$distance = levenshtein($missingProp, $extraCol);

				// Consider it a typo if distance is 1-3 characters
				if ($distance > 0 && $distance <= 3 && $distance < $bestDistance) {
					$bestDistance = $distance;
					$bestMatch = $extraCol;
				}
			}

			if ($bestMatch !== null) {
				$typos[$missingProp] = $bestMatch;
			}
		}

		return $typos;
	}

	/**
	 * Extract SQL strings assigned to variables in a method
	 * Optimized: Early bailouts and skip empty methods
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
				!(
					$stmt instanceof Node\Stmt\Expression
					&& $stmt->expr instanceof Node\Expr\Assign
				)
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
}
