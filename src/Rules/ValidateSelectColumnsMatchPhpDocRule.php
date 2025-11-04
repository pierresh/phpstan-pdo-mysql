<?php declare(strict_types=1);

namespace Pierresh\PhpStanPdoMysql\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Scalar\String_;
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
		$errors = [];

		// Skip migration files
		$filePath = $scope->getFile();
		if (strpos($filePath, '/migrations/') !== false) {
			return [];
		}

		// Extract @phpstan-type definitions from class PHPDoc
		$typeAliases = $this->extractTypeAliases($node);

		// Extract property preparations from constructor and methods
		$propertyPreparations = $this->extractPropertyPreparations($node);

		// Check each method for @var annotations
		// Note: We only validate @var annotations, not @return annotations
		// because @var describes what was EXTRACTED from the database,
		// while @return describes what the method returns (which may be different)
		$seen = [];
		foreach ($node->getMethods() as $method) {
			// Validate @var annotations within method body
			$varAnnotations = $this->extractVarAnnotations($method, $propertyPreparations, $typeAliases);
			foreach ($varAnnotations as $varInfo) {
				// Create a unique key to avoid duplicate validations
				$key = $varInfo['var_line'] . ':' . $varInfo['sql_line'] . ':' . json_encode($varInfo['object_shape']);
				if (!isset($seen[$key])) {
					$seen[$key] = true;
					$errors = array_merge(
						$errors,
						$this->validateSqlAgainstPhpDoc(
							$varInfo['sql'],
							$varInfo['sql_line'],
							$varInfo['object_shape'],
							$varInfo['var_line']
						)
					);
				}
			}
		}

		return $errors;
	}

	/**
	 * Extract @phpstan-type definitions from class PHPDoc
	 *
	 * @return array<string, array<string, string>> Type alias name => object shape
	 */
	private function extractTypeAliases(Class_ $class): array
	{
		$aliases = [];

		$docComment = $class->getDocComment();
		if ($docComment === null) {
			return [];
		}

		$docText = $docComment->getText();
		$lines = explode("\n", $docText);

		foreach ($lines as $line) {
			// Match @phpstan-type AliasName object{prop1: type1, prop2: type2}
			$matchCount = preg_match('/@phpstan-type\s+(\w+)\s+object\s*\{([^}]+)\}/s', $line, $matches);
			if ($matchCount !== false && $matchCount > 0) {
				$aliasName = $matches[1];
				$shapeContent = $matches[2];

				$properties = [];
				$parts = explode(',', $shapeContent);

				foreach ($parts as $part) {
					$part = trim($part);
					$propMatchCount = preg_match('/^(\w+)\s*:\s*(.+)$/', $part, $propMatch);
					if ($propMatchCount !== false && $propMatchCount > 0) {
						$properties[$propMatch[1]] = trim($propMatch[2]);
					}
				}

				if (count($properties) > 0) {
					$aliases[$aliasName] = $properties;
				}
			}
		}

		// Handle multiline @phpstan-type definitions
		$cleanedDocText = preg_replace('/\s*\*\s*/m', ' ', $docText); // Remove * from doc comments
		if (!is_string($cleanedDocText)) {
			return $aliases;
		}
		$multilineMatchCount = preg_match_all('/@phpstan-type\s+(\w+)\s+object\s*\{([^}]+)\}/s', $cleanedDocText, $matches, PREG_SET_ORDER);
		if ($multilineMatchCount !== false && $multilineMatchCount > 0) {
			foreach ($matches as $match) {
				$aliasName = $match[1];
				$shapeContent = $match[2];

				$properties = [];
				$parts = explode(',', $shapeContent);

				foreach ($parts as $part) {
					$part = trim($part);
					$propMatchCount2 = preg_match('/^(\w+)\s*:\s*(.+)$/', $part, $propMatch);
					if ($propMatchCount2 !== false && $propMatchCount2 > 0) {
						$properties[$propMatch[1]] = trim($propMatch[2]);
					}
				}

				if (count($properties) > 0) {
					$aliases[$aliasName] = $properties;
				}
			}
		}

		return $aliases;
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

		foreach ($class->getMethods() as $method) {
			// First, extract SQL variables in this method
			$sqlVariables = $this->extractSqlVariablesFromMethod($method);

			foreach ($method->getStmts() ?? [] as $stmt) {
				if ($stmt instanceof Node\Stmt\Expression && $stmt->expr instanceof Node\Expr\Assign) {
					$assign = $stmt->expr;

					// Check if left side is $this->property
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

					// Check if right side is prepare()
					if (!$assign->expr instanceof MethodCall) {
						continue;
					}

					$methodCall = $assign->expr;
					if (
						$methodCall->name instanceof Node\Identifier &&
						$methodCall->name->toString() === 'prepare' &&
						count($methodCall->getArgs()) > 0
					) {
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
	 * @return array<array{sql: string, sql_line: int, object_shape: array<string, string>, var_line: int}>
	 */
	private function extractVarAnnotations(ClassMethod $method, array $propertyPreparations, array $typeAliases): array
	{
		$annotations = [];

		// First, collect all @var annotations in this method
		$varShapes = [];
		foreach ($method->getStmts() ?? [] as $stmt) {
			$this->collectVarAnnotationsRecursive($stmt, $varShapes, $typeAliases);
		}

		if (count($varShapes) === 0) {
			return [];
		}

		// Find SQL queries in this method (local prepare() or property usage)
		$sqlQueries = [];

		// Check for local prepare() calls
		$prepareStatements = $this->extractPrepareStatementsFromMethod($method);
		foreach ($prepareStatements as $prep) {
			$sqlQueries[] = $prep;
		}

		// Check for property usage
		$propertiesUsed = $this->extractPropertiesUsedForFetch($method);
		foreach ($propertiesUsed as $propertyName) {
			if (isset($propertyPreparations[$propertyName])) {
				$sqlQueries[] = $propertyPreparations[$propertyName];
			}
		}

		// Match each @var annotation with SQL queries by variable name
		foreach ($varShapes as $varInfo) {
			$matchedSql = null;

			// If we know which variable is being fetched from, match by variable name
			if ($varInfo['fetch_var'] !== null) {
				foreach ($sqlQueries as $sqlInfo) {
					if (isset($sqlInfo['var']) && $sqlInfo['var'] === $varInfo['fetch_var']) {
						$matchedSql = $sqlInfo;
						break;
					}
				}
			}

			// Fallback: if no variable match or no fetch_var, use closest SQL before @var
			if ($matchedSql === null) {
				$closestDistance = PHP_INT_MAX;
				foreach ($sqlQueries as $sqlInfo) {
					if ($sqlInfo['line'] < $varInfo['line']) {
						$distance = $varInfo['line'] - $sqlInfo['line'];
						if ($distance < $closestDistance) {
							$closestDistance = $distance;
							$matchedSql = $sqlInfo;
						}
					}
				}
			}

			// If we found a matching SQL query, validate it
			if ($matchedSql !== null) {
				$annotations[] = [
					'sql' => $matchedSql['sql'],
					'sql_line' => $matchedSql['line'],
					'object_shape' => $varInfo['object_shape'],
					'var_line' => $varInfo['line'],
				];
			}
		}

		return $annotations;
	}

	/**
	 * Recursively collect @var object{...} annotations
	 *
	 * @param array<array{line: int, object_shape: array<string, string>, fetch_var: string|null}> &$varShapes
	 * @param array<string, array<string, string>> $typeAliases
	 */
	private function collectVarAnnotationsRecursive(Node $node, array &$varShapes, array $typeAliases): void
	{
		$docComment = $node->getDocComment();
		if ($docComment !== null) {
			$docText = $docComment->getText();

			// Only process @var comments on statement nodes (not expression nodes)
			// to avoid processing the same comment multiple times as it bubbles down the AST
			$isStatementNode = $node instanceof Node\Stmt\Expression ||
			                   $node instanceof Node\Stmt\Return_;

			if ($isStatementNode) {
				// First try to extract inline object shape: @var object{...}
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

					// Try to find which variable is being assigned in the next statement
					// Pattern: /** @var ... */ $user = $stmt->fetch();
					$fetchVar = $this->getFetchVariableAfterComment($node);

					$varShapes[] = [
						'line' => $varLine,
						'object_shape' => $objectShape,
						'fetch_var' => $fetchVar,
					];
				}
			}
		}

		// Recurse into child nodes
		foreach ($node->getSubNodeNames() as $subNodeName) {
			$subNode = $node->$subNodeName;

			if (is_array($subNode)) {
				foreach ($subNode as $item) {
					if ($item instanceof Node) {
						$this->collectVarAnnotationsRecursive($item, $varShapes, $typeAliases);
					}
				}
			} elseif ($subNode instanceof Node) {
				$this->collectVarAnnotationsRecursive($subNode, $varShapes, $typeAliases);
			}
		}
	}

	/**
	 * Get the line number of the @var annotation
	 */
	private function getVarAnnotationLine(\PhpParser\Comment\Doc $docComment): int
	{
		$docStartLine = $docComment->getStartLine();
		$lines = explode("\n", $docComment->getText());
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
	 * Extract which variable is being fetched from in a statement with a var comment
	 * Pattern: comment followed by assignment like user = stmt->fetch()
	 * We want to extract "stmt" (the variable being fetched from)
	 */
	private function getFetchVariableAfterComment(Node $node): ?string
	{
		// The node with the @var comment could be an assignment
		if ($node instanceof Node\Stmt\Expression && $node->expr instanceof Node\Expr\Assign) {
			$assign = $node->expr;
			// Check if right side is a method call (fetch/fetchObject)
			if ($assign->expr instanceof MethodCall) {
				$methodCall = $assign->expr;
				if (
					$methodCall->name instanceof Node\Identifier &&
					($methodCall->name->toString() === 'fetch' || $methodCall->name->toString() === 'fetchObject')
				) {
					// Extract the variable being called on
					if ($methodCall->var instanceof Variable && is_string($methodCall->var->name)) {
						return $methodCall->var->name;
					}
				}
			}
		}

		return null;
	}

	/**
	 * Extract properties used in fetchObject() calls
	 *
	 * @return array<string> Property names like '$this->query'
	 */
	private function extractPropertiesUsedForFetch(ClassMethod $method): array
	{
		$properties = [];

		foreach ($method->getStmts() ?? [] as $stmt) {
			$this->findFetchCallsRecursive($stmt, $properties);
		}

		return array_unique($properties);
	}

	/**
	 * Recursively find fetchObject() calls and track properties
	 *
	 * @param array<string> &$properties
	 */
	private function findFetchCallsRecursive(Node $node, array &$properties): void
	{
		// Check if current node is fetchObject() call on a property
		if ($node instanceof MethodCall) {
			if (
				$node->name instanceof Node\Identifier &&
				($node->name->toString() === 'fetchObject' || $node->name->toString() === 'fetch')
			) {
				// Check if it's called on a property
				if ($node->var instanceof PropertyFetch) {
					$propertyFetch = $node->var;
					if (
						$propertyFetch->var instanceof Variable &&
						$propertyFetch->var->name === 'this' &&
						$propertyFetch->name instanceof Node\Identifier
					) {
						$properties[] = '$this->' . $propertyFetch->name->toString();
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
		?int $reportLine = null
	): array {
		$errors = [];
		$reportLine = $reportLine ?? $sqlLine;

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
		// Check for extra columns (in SELECT but not in PHPDoc)
		$extraInSelect = array_diff($actualColumns, $expectedProps);
		// Check for typos
		$typos = $this->findPotentialTypos($missingInSelect, $extraInSelect);

		foreach ($missingInSelect as $prop) {
			if (isset($typos[$prop])) {
				$errors[] = RuleErrorBuilder::message(
					sprintf(
						'SELECT column mismatch: PHPDoc expects property "%s" but SELECT (line %d) has "%s" - possible typo?',
						$prop,
						$sqlLine,
						$typos[$prop]
					)
				)->line($reportLine)->build();
			} else {
				$errors[] = RuleErrorBuilder::message(
					sprintf(
						'SELECT column missing: PHPDoc expects property "%s" but it is not in the SELECT query (line %d)',
						$prop,
						$sqlLine
					)
				)->line($reportLine)->build();
			}
		}

		foreach ($extraInSelect as $col) {
			$isTypo = false;
			foreach ($typos as $suggestion) {
				if ($suggestion === $col) {
					$isTypo = true;
					break;
				}
			}

			if (!$isTypo) {
				$errors[] = RuleErrorBuilder::message(
					sprintf(
						'SELECT column extra: SELECT (line %d) has column "%s" but it is not in the PHPDoc object shape',
						$sqlLine,
						$col
					)
				)->line($reportLine)->build();
			}
		}

		return $errors;
	}

	/**
	 * Extract object shape from @return or @var object{...} annotation
	 *
	 * @return array<string, string>|null Property name => type
	 */
	private function extractObjectShapeFromPhpDoc(string $docComment, string $annotation = '@return'): ?array
	{
		// Match @return or @var object{prop1: type1, prop2: type2}
		$pattern = '/' . preg_quote($annotation, '/') . '\s+object\s*\{([^}]+)\}/';
		$matchCount = preg_match($pattern, $docComment, $matches);
		if ($matchCount === false || $matchCount === 0) {
			return null;
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

		return count($properties) > 0 ? $properties : null;
	}

	/**
	 * Extract prepare() statements from the method
	 * Now supports both direct strings and variables
	 *
	 * @return array<array{sql: string, line: int, var?: string}>
	 */
	private function extractPrepareStatementsFromMethod(ClassMethod $method): array
	{
		$statements = [];

		// First, extract SQL variables in this method
		$sqlVariables = $this->extractSqlVariablesFromMethod($method);

		foreach ($method->getStmts() ?? [] as $stmt) {
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
	private function findPrepareCallsRecursive(Node $node, array &$statements, array $sqlVariables): void
	{
		// Check if current node is a prepare() call
		if ($node instanceof Node\Stmt\Expression && $node->expr instanceof MethodCall) {
			$methodCall = $node->expr;

			if (
				$methodCall->name instanceof Node\Identifier &&
				$methodCall->name->toString() === 'prepare' &&
				count($methodCall->getArgs()) > 0
			) {
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
					$statements[] = [
						'sql' => $sql,
						'line' => $node->getStartLine(),
						'var' => null, // No variable assignment
					];
				}
			}
		}

		// Also check assignments: $var = $db->prepare(...)
		if ($node instanceof Node\Stmt\Expression && $node->expr instanceof Node\Expr\Assign) {
			$assign = $node->expr;
			if ($assign->expr instanceof MethodCall) {
				$methodCall = $assign->expr;

				if (
					$methodCall->name instanceof Node\Identifier &&
					$methodCall->name->toString() === 'prepare' &&
					count($methodCall->getArgs()) > 0
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
					}
					// Case 2: Variable reference
					elseif ($firstArg instanceof Variable && is_string($firstArg->name)) {
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
	private function extractSelectColumns(string $sql): ?array
	{
		// Remove comments and normalize whitespace
		$sql = (string) preg_replace('/--.*$/m', '', $sql);
		$sql = (string) preg_replace('/\/\*.*?\*\//s', '', $sql);
		$sql = (string) preg_replace('/\s+/', ' ', trim($sql));

		// Match SELECT ... FROM pattern
		$selectMatchCount = preg_match('/^\s*SELECT\s+(.*?)\s+FROM\s+/i', $sql, $matches);
		if ($selectMatchCount === false || $selectMatchCount === 0) {
			return null;
		}

		$selectPart = $matches[1];

		// Handle SELECT *
		if (trim($selectPart) === '*') {
			return null; // Can't validate SELECT *
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
					$keywords = ['FROM', 'WHERE', 'JOIN', 'LEFT', 'RIGHT', 'INNER', 'OUTER', 'ON'];
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

		return count($columns) > 0 ? array_values(array_unique($columns)) : null;
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
			if (!($stmt instanceof Node\Stmt\Expression && $stmt->expr instanceof Node\Expr\Assign)) {
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
