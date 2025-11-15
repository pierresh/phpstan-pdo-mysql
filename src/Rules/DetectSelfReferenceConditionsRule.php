<?php declare(strict_types=1);

namespace Pierresh\PhpStanPdoMysql\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use SqlFtw\Parser\InvalidCommand;
use SqlFtw\Parser\Parser as SqlFtwParser;
use SqlFtw\Parser\ParserConfig;
use SqlFtw\Platform\Platform;
use SqlFtw\Session\Session;
use SqlFtw\Sql\Dml\Insert\InsertSelectCommand;
use SqlFtw\Sql\Dml\Query\SelectCommand;
use SqlFtw\Sql\Dml\TableReference\InnerJoin;
use SqlFtw\Sql\Dml\TableReference\OuterJoin;
use SqlFtw\Sql\Expression\BinaryOperator;
use SqlFtw\Sql\Expression\ComparisonOperator;
use SqlFtw\Sql\Expression\QualifiedName;

/**
 * This rule detects useless self-reference conditions in SQL queries.
 *
 * Examples of errors it catches:
 * - INNER JOIN sp_list ON sp_list.sp_id = sp_list.sp_id (same column on both sides)
 * - WHERE users.id = users.id (comparing column to itself)
 *
 * This is almost always a bug where the developer meant to reference a different table.
 *
 * @implements Rule<ClassMethod>
 */
class DetectSelfReferenceConditionsRule implements Rule
{
	private readonly SqlFtwParser $sqlFtwParser;

	public function __construct()
	{
		// Initialize SQLFTW parser
		$platform = Platform::get(Platform::MYSQL, '8.0');
		$parserConfig = new ParserConfig($platform);
		$session = new Session($platform);
		$this->sqlFtwParser = new SqlFtwParser($parserConfig, $session);
	}

	public function getNodeType(): string
	{
		return ClassMethod::class;
	}

	public function processNode(Node $node, Scope $scope): array
	{
		$errors = [];

		// Two-pass analysis: collect SQL, then validate
		$sqlQueries = $this->extractSqlQueries($node);

		foreach ($sqlQueries as $sqlQuery) {
			$errors = array_merge($errors, $this->checkForSelfReferences(
				$sqlQuery['sql'],
				$sqlQuery['line'],
			));
		}

		return $errors;
	}

	/**
	 * Extract SQL query strings from prepare() and query() calls
	 *
	 * @return array<array{sql: string, line: int}>
	 */
	private function extractSqlQueries(ClassMethod $classMethod): array
	{
		$queries = [];

		// First, collect all SQL string variables
		$sqlVariables = $this->extractSqlVariables($classMethod);

		// Find all prepare() and query() calls
		foreach ($classMethod->getStmts() ?? [] as $stmt) {
			$this->findSqlCallsRecursive($stmt, $queries, $sqlVariables);
		}

		return $queries;
	}

	/**
	 * Extract SQL strings assigned to variables
	 *
	 * @return array<string, string> Variable name => SQL string
	 */
	private function extractSqlVariables(ClassMethod $classMethod): array
	{
		$sqlVariables = [];

		foreach ($classMethod->getStmts() ?? [] as $stmt) {
			if ($stmt instanceof Node\Stmt\Expression && $stmt->expr instanceof Assign) {
				$assign = $stmt->expr;

				// Check if left side is a simple variable
				// Check if right side is a string
				if (
					$assign->var instanceof Variable
					&& is_string($assign->var->name)
					&& $assign->expr instanceof String_
				) {
					$sql = $assign->expr->value;
					// Simple heuristic: if it contains SQL keywords, consider it SQL
					if ($this->looksLikeSQL($sql)) {
						$varName = $assign->var->name;
						$sqlVariables[$varName] = $sql;
					}
				}
			}
		}

		return $sqlVariables;
	}

	/**
	 * Recursively find prepare() and query() calls
	 *
	 * @param array<array{sql: string, line: int}> &$queries
	 * @param array<string, string> $sqlVariables
	 */
	private function findSqlCallsRecursive(
		Node $node,
		array &$queries,
		array $sqlVariables,
	): void {
		// Check if this is a prepare() or query() call
		if (
			$node instanceof Node\Stmt\Expression
			&& $node->expr instanceof MethodCall
		) {
			$methodCall = $node->expr;

			if ($methodCall->name instanceof Node\Identifier) {
				$methodName = $methodCall->name->toString();

				if (
					($methodName === 'prepare' || $methodName === 'query')
					&& $methodCall->getArgs() !== []
				) {
					$firstArg = $methodCall->getArgs()[0]->value;
					$sql = null;

					// Case 1: Direct string literal
					if ($firstArg instanceof String_) {
						$sql = $firstArg->value;
					} elseif ($firstArg instanceof Variable && is_string($firstArg->name)) {
						// Case 2: Variable reference
						$varName = $firstArg->name;
						if (isset($sqlVariables[$varName])) {
							$sql = $sqlVariables[$varName];
						}
					}

					if ($sql !== null) {
						$queries[] = [
							'sql' => $sql,
							'line' => $node->getStartLine(),
						];
					}
				}
			}
		}

		// Also check assignments: $var = $db->prepare(...)
		if ($node instanceof Node\Stmt\Expression && $node->expr instanceof Assign) {
			$assign = $node->expr;
			if ($assign->expr instanceof MethodCall) {
				$methodCall = $assign->expr;

				if ($methodCall->name instanceof Node\Identifier) {
					$methodName = $methodCall->name->toString();

					if (
						($methodName === 'prepare' || $methodName === 'query')
						&& $methodCall->getArgs() !== []
					) {
						$firstArg = $methodCall->getArgs()[0]->value;
						$sql = null;

						// Case 1: Direct string literal
						if ($firstArg instanceof String_) {
							$sql = $firstArg->value;
						} elseif ($firstArg instanceof Variable && is_string($firstArg->name)) {
							// Case 2: Variable reference
							$varName = $firstArg->name;
							if (isset($sqlVariables[$varName])) {
								$sql = $sqlVariables[$varName];
							}
						}

						if ($sql !== null) {
							$queries[] = [
								'sql' => $sql,
								'line' => $node->getStartLine(),
							];
						}
					}
				}
			}
		}

		// Recurse into child nodes
		foreach ($node->getSubNodeNames() as $subNodeName) {
			$subNode = $node->$subNodeName;

			if (is_array($subNode)) {
				foreach ($subNode as $item) {
					if ($item instanceof Node) {
						$this->findSqlCallsRecursive($item, $queries, $sqlVariables);
					}
				}
			} elseif ($subNode instanceof Node) {
				$this->findSqlCallsRecursive($subNode, $queries, $sqlVariables);
			}
		}
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
	 * Check SQL query for self-reference conditions
	 *
	 * @return array<\PHPStan\Rules\RuleError>
	 */
	private function checkForSelfReferences(string $sql, int $line): array
	{
		$errors = [];

		// Reset pattern occurrences, cache, and alias map for this SQL query
		$this->patternOccurrences = [];
		$this->patternLineCache = [];
		$this->aliasMap = [];

		// Skip if SQLFTW is not available
		if (!class_exists(SqlFtwParser::class)) {
			return [];
		}

		// Preprocess SQL: replace :placeholders with dummy values for parsing
		// SQLFTW doesn't understand PDO placeholders, but we don't need them for structure analysis
		$preprocessedSql = preg_replace('/:([a-zA-Z_]\w*)/', '1', $sql);
		if (!is_string($preprocessedSql)) {
			return [];
		}

		try {
			$commands = $this->sqlFtwParser->parse($preprocessedSql);

			foreach ($commands as $command) {
				// Skip invalid commands
				if ($command instanceof InvalidCommand) {
					continue;
				}

				// Get the SELECT part (either from SELECT or INSERT...SELECT)
				$selectCommand = null;
				if ($command instanceof SelectCommand) {
					$selectCommand = $command;
				} elseif ($command instanceof InsertSelectCommand) {
					$query = $command->getQuery();
					if ($query instanceof SelectCommand) {
						$selectCommand = $query;
					}
				}

				// Only process if we have a SELECT
				if (!$selectCommand instanceof \SqlFtw\Sql\Dml\Query\SelectCommand) {
					continue;
				}

				// Build alias map from FROM clause
				$from = $selectCommand->getFrom();
				if ($from instanceof \SqlFtw\Sql\Dml\TableReference\TableReferenceNode) {
					$this->buildAliasMap($from);
				}

				// Check JOIN conditions
				if ($from instanceof \SqlFtw\Sql\Dml\TableReference\TableReferenceNode) {
					$joinErrors = $this->checkJoinConditions($from, $line, $sql);
					$errors = array_merge($errors, $joinErrors);
				}

				// Check WHERE conditions
				$where = $selectCommand->getWhere();
				if ($where instanceof \SqlFtw\Sql\Expression\ExpressionNode) {
					$whereErrors = $this->checkWhereCondition($where, $line, $sql);
					$errors = array_merge($errors, $whereErrors);
				}
			}
		} catch (\Throwable) {
			// Silently skip if SQL parsing fails
			return [];
		}

		return $errors;
	}

	/**
	 * Check JOIN conditions for self-references
	 *
	 * @return array<\PHPStan\Rules\RuleError>
	 */
	private function checkJoinConditions(
		object $tableRef,
		int $baseLine,
		string $originalSql,
	): array {
		$errors = [];

		// Check if this is a JOIN node
		if ($tableRef instanceof InnerJoin || $tableRef instanceof OuterJoin) {
			// Recursively check left and right sides of JOIN first (to preserve order)
			$left = $tableRef->getLeft();
			$leftErrors = $this->checkJoinConditions($left, $baseLine, $originalSql);
			$errors = array_merge($errors, $leftErrors);

			// Check condition after left side
			$condition = $tableRef->getCondition();
			if ($condition instanceof ComparisonOperator) {
				$error = $this->checkComparisonForSelfReference(
					$condition,
					$baseLine,
					$originalSql,
					'JOIN',
				);
				if ($error instanceof \PHPStan\Rules\RuleError) {
					$errors[] = $error;
				}
			}

			// Check right side
			$right = $tableRef->getRight();
			$rightErrors = $this->checkJoinConditions($right, $baseLine, $originalSql);
			$errors = array_merge($errors, $rightErrors);
		}

		return $errors;
	}

	/**
	 * Recursively check WHERE conditions for self-references
	 *
	 * @return array<\PHPStan\Rules\RuleError>
	 */
	private function checkWhereCondition(
		object $expr,
		int $baseLine,
		string $originalSql,
	): array {
		$errors = [];

		// If it's a comparison, check for self-reference
		if ($expr instanceof ComparisonOperator) {
			$error = $this->checkComparisonForSelfReference(
				$expr,
				$baseLine,
				$originalSql,
				'WHERE',
			);
			if ($error instanceof \PHPStan\Rules\RuleError) {
				$errors[] = $error;
			}
		}

		// If it's a binary operator (AND/OR), recursively check both sides
		if ($expr instanceof BinaryOperator) {
			$left = $expr->getLeft();
			$leftErrors = $this->checkWhereCondition($left, $baseLine, $originalSql);
			$errors = array_merge($errors, $leftErrors);

			$right = $expr->getRight();
			$rightErrors = $this->checkWhereCondition($right, $baseLine, $originalSql);
			$errors = array_merge($errors, $rightErrors);
		}

		return $errors;
	}

	/**
	 * Track occurrences of each self-reference pattern for line-accurate reporting
	 * @var array<string, int>
	 */
	private array $patternOccurrences = [];

	/**
	 * Cache of pattern line positions to avoid repeated regex scanning
	 * @var array<string, array<int, int>> Map of pattern => [occurrence_index => line_number]
	 */
	private array $patternLineCache = [];

	/**
	 * Map of table aliases to actual table names for the current query
	 * @var array<string, string> Map of alias => table_name
	 */
	private array $aliasMap = [];

	/**
	 * Build a map of table aliases from the FROM clause
	 * Recursively processes JOINs to collect all aliases
	 */
	private function buildAliasMap(object $tableRef): void
	{
		// Check if this is a table reference with an alias
		if ($tableRef instanceof \SqlFtw\Sql\Dml\TableReference\TableReferenceTable) {
			$tableName = $tableRef->getTable()->getFullName();
			$alias = $tableRef->getAlias();

			if ($alias !== null) {
				// Store alias => table mapping
				$this->aliasMap[$alias] = $tableName;
			}

			// Also map table name to itself for consistent resolution
			$this->aliasMap[$tableName] = $tableName;
		}

		// Recursively process JOINs
		if ($tableRef instanceof InnerJoin || $tableRef instanceof OuterJoin) {
			$this->buildAliasMap($tableRef->getLeft());
			$this->buildAliasMap($tableRef->getRight());
		}
	}

	/**
	 * Resolve a table reference to its base table name
	 * If it's an alias, return the actual table name
	 * Otherwise, return the original name
	 */
	private function resolveTableName(string $tableOrAlias): string
	{
		return $this->aliasMap[$tableOrAlias] ?? $tableOrAlias;
	}

	/**
	 * Check if a comparison operator references the same column on both sides
	 */
	private function checkComparisonForSelfReference(
		ComparisonOperator $comparisonOperator,
		int $baseLine,
		string $originalSql,
		string $context,
	): null|\PHPStan\Rules\RuleError {
		$rootNode = $comparisonOperator->getLeft();
		$right = $comparisonOperator->getRight();

		// Both sides must be QualifiedName (table.column)
		if (
			!($rootNode instanceof QualifiedName && $right instanceof QualifiedName)
		) {
			return null;
		}

		// Get full names (table.column or alias.column)
		$leftFullName = $rootNode->getFullName();
		$rightFullName = $right->getFullName();

		// Parse table and column from qualified names
		$leftParts = explode('.', $leftFullName);
		$rightParts = explode('.', $rightFullName);

		// We need at least table.column format
		if (count($leftParts) < 2 || count($rightParts) < 2) {
			return null;
		}

		$leftTable = $leftParts[0];
		$leftColumn = $leftParts[1];
		$rightTable = $rightParts[0];
		$rightColumn = $rightParts[1];

		// Resolve aliases to actual table names
		$resolvedLeftTable = $this->resolveTableName($leftTable);
		$resolvedRightTable = $this->resolveTableName($rightTable);

		// Check if both sides reference the same table.column (after alias resolution)
		if (
			$resolvedLeftTable === $resolvedRightTable
			&& $leftColumn === $rightColumn
		) {
			// Track occurrence of this specific pattern
			$pattern = sprintf('%s = %s', $leftFullName, $rightFullName);
			if (!isset($this->patternOccurrences[$pattern])) {
				$this->patternOccurrences[$pattern] = 0;
			}

			$occurrenceIndex = $this->patternOccurrences[$pattern];
			$this->patternOccurrences[$pattern]++;

			// Calculate the actual PHP line number where the error occurs
			// Pass the actual pattern found in SQL (not the resolved pattern)
			$errorLine = $this->calculateErrorLine(
				$leftFullName,
				$rightFullName,
				$baseLine,
				$originalSql,
				$occurrenceIndex,
			);

			return RuleErrorBuilder::message(sprintf(
				"Self-referencing %s condition: '%s = %s'",
				$context,
				$leftFullName,
				$rightFullName,
			))
				->line($errorLine)
				->identifier('pdoSql.selfReferenceCondition')
				->build();
		}

		return null;
	}

	/**
	 * Calculate the actual PHP line number for an error based on SQL token position
	 *
	 * Since SQLFTW doesn't provide token positions for expression nodes, we use a heuristic:
	 * Find the Nth occurrence of the self-reference pattern in the SQL,
	 * where N is determined by the occurrenceIndex parameter.
	 *
	 * Uses caching to avoid repeated regex scanning for the same pattern.
	 */
	private function calculateErrorLine(
		string $leftFullName,
		string $rightFullName,
		int $baseLine,
		string $originalSql,
		int $occurrenceIndex,
	): int {
		$pattern = sprintf('%s = %s', $leftFullName, $rightFullName); // Pattern key for cache

		// Check cache first
		if (isset($this->patternLineCache[$pattern][$occurrenceIndex])) {
			return $baseLine + $this->patternLineCache[$pattern][$occurrenceIndex];
		}

		// Build all occurrences for this pattern at once (cache miss)
		if (!isset($this->patternLineCache[$pattern])) {
			$this->patternLineCache[$pattern] = [];
			$lines = explode("\n", $originalSql);

			// Build regex pattern to match with flexible whitespace
			// Escape dots in table.column names for regex
			$escapedLeft = preg_quote($leftFullName, '/');
			$escapedRight = preg_quote($rightFullName, '/');
			$selfRefRegex = '/' . $escapedLeft . '\s*=\s*' . $escapedRight . '/';

			// Find ALL occurrences and cache them
			$occurrence = 0;
			foreach ($lines as $index => $line) {
				if (preg_match($selfRefRegex, $line) === 1) {
					$this->patternLineCache[$pattern][$occurrence] = $index;
					$occurrence++;
				}
			}
		}

		// Get from cache (or default to 0 if not found)
		$sqlRow = $this->patternLineCache[$pattern][$occurrenceIndex] ?? 0;

		// Calculate PHP line: baseLine is the prepare() line
		// The SQL string starts on baseLine with the opening quote
		// SQL index 0 = baseLine (the line with opening quote)
		// SQL index 1 = baseLine + 1, etc.
		return $baseLine + $sqlRow;
	}
}
