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
use SqlFtw\Sql\Expression\QualifiedName;

/**
 * This rule detects invalid table references in SQL queries.
 *
 * Examples of errors it catches:
 * - SELECT user.name FROM users (table 'user' doesn't exist, should be 'users')
 * - SELECT usr.name FROM users AS u (wrong alias 'usr', should be 'u')
 * - SELECT orders.id FROM users (table 'orders' not in FROM/JOIN clauses)
 *
 * @implements Rule<ClassMethod>
 */
class DetectInvalidTableReferencesRule implements Rule
{
	private readonly SqlFtwParser $sqlFtwParser;

	/** @var array<string, bool> Available tables and aliases for current query */
	private array $availableTables = [];

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
			$errors = array_merge($errors, $this->validateTableReferences(
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
	 * Recursively find SQL in prepare() and query() calls
	 *
	 * @param array<array{sql: string, line: int}> $queries
	 * @param array<string, string> $sqlVariables
	 */
	private function findSqlCallsRecursive(
		Node $node,
		array &$queries,
		array $sqlVariables,
	): void {
		// Check if this node is a prepare() or query() call
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
	 * Validate table references in SQL query
	 *
	 * @return array<\PHPStan\Rules\RuleError>
	 */
	private function validateTableReferences(string $sql, int $line): array
	{
		$errors = [];

		// Reset available tables for this query
		$this->availableTables = [];

		// Skip if SQLFTW is not available
		if (!class_exists(SqlFtwParser::class)) {
			return [];
		}

		// Performance: Skip if SQL doesn't contain qualified names (no dots)
		// Simple heuristic: if there's no dot, there are no table.column references
		if (!str_contains($sql, '.')) {
			return [];
		}

		// Performance: Skip very long queries (>10,000 chars) to avoid slowdowns
		if (strlen($sql) > 10000) {
			return [];
		}

		// Performance: Only process SELECT queries (this rule only works with SELECT)
		$upperSql = strtoupper(trim($sql));
		if (
			!str_starts_with($upperSql, 'SELECT')
			&& !str_contains($upperSql, 'INSERT')
		) {
			return [];
		}

		// Preprocess SQL: replace :placeholders with dummy values for parsing
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
				if (!$selectCommand instanceof SelectCommand) {
					continue;
				}

				// Build available tables map from FROM clause
				$from = $selectCommand->getFrom();
				if ($from instanceof \SqlFtw\Sql\Dml\TableReference\TableReferenceNode) {
					$this->buildAvailableTablesMap($from);
				}

				// Extract and validate qualified column references
				$invalidRefs = $this->findInvalidTableReferences($selectCommand);
				$availableList = implode(', ', array_keys($this->availableTables));

				// Find all occurrences of each invalid table reference
				foreach ($invalidRefs as $invalidRef) {
					$offsets = $this->findAllTableReferenceOffsets($sql, $invalidRef);

					foreach ($offsets as $offset) {
						$errors[] = RuleErrorBuilder::message(sprintf(
							"Invalid table reference '%s' - available tables/aliases: %s",
							$invalidRef,
							$availableList,
						))
							->line($line + $offset)
							->identifier('pdoSql.invalidTableReference')
							->build();
					}
				}
			}
		} catch (\Throwable) {
			// Silently skip if SQL parsing fails
			return [];
		}

		return $errors;
	}

	/**
	 * Build a map of available tables and aliases from the FROM clause
	 * Recursively processes JOINs to collect all tables and aliases
	 */
	private function buildAvailableTablesMap(object $tableRef): void
	{
		// Check if this is a table reference
		if ($tableRef instanceof \SqlFtw\Sql\Dml\TableReference\TableReferenceTable) {
			$tableName = $tableRef->getTable()->getFullName();
			$alias = $tableRef->getAlias();

			// Add the alias if it exists
			if ($alias !== null) {
				$this->availableTables[$alias] = true;
			}

			// Always add the actual table name (can be used even when alias exists)
			$this->availableTables[$tableName] = true;
		}

		// Recursively process JOINs
		if ($tableRef instanceof InnerJoin || $tableRef instanceof OuterJoin) {
			$this->buildAvailableTablesMap($tableRef->getLeft());
			$this->buildAvailableTablesMap($tableRef->getRight());
		}
	}

	/**
	 * Find all invalid table references in the SELECT command
	 *
	 * @return array<string> List of invalid table names
	 */
	private function findInvalidTableReferences(SelectCommand $selectCommand): array
	{
		$invalidTables = [];

		// Check SELECT columns
		$columns = $selectCommand->getColumns();
		if ($columns !== null) {
			foreach ($columns as $column) {
				$expr = $column->getExpression();
				$this->extractQualifiedNamesRecursive($expr, $invalidTables);
			}
		}

		// Check WHERE clause
		$where = $selectCommand->getWhere();
		if ($where instanceof \SqlFtw\Sql\Expression\ExpressionNode) {
			$this->extractQualifiedNamesRecursive($where, $invalidTables);
		}

		// Check ORDER BY clause
		$orderBy = $selectCommand->getOrderBy();
		if ($orderBy !== null) {
			foreach ($orderBy as $orderItem) {
				$expr = $orderItem->getExpression();
				$this->extractQualifiedNamesRecursive($expr, $invalidTables);
			}
		}

		// Check GROUP BY clause
		$groupBy = $selectCommand->getGroupBy();
		if ($groupBy !== null) {
			foreach ($groupBy as $expr) {
				$this->extractQualifiedNamesRecursive($expr, $invalidTables);
			}
		}

		// Check HAVING clause
		$having = $selectCommand->getHaving();
		if ($having instanceof \SqlFtw\Sql\Expression\ExpressionNode) {
			$this->extractQualifiedNamesRecursive($having, $invalidTables);
		}

		// Check JOIN conditions
		$from = $selectCommand->getFrom();
		if ($from instanceof \SqlFtw\Sql\Dml\TableReference\TableReferenceNode) {
			$this->extractQualifiedNamesFromJoins($from, $invalidTables);
		}

		return array_unique($invalidTables);
	}

	/**
	 * Recursively extract qualified names from an expression
	 *
	 * @param array<string> $invalidTables
	 */
	private function extractQualifiedNamesRecursive(
		mixed $expr,
		array &$invalidTables,
	): void {
		if ($expr instanceof QualifiedName) {
			// getSchema() returns the table/alias name (e.g., "users" in "users.id")
			$tableName = $expr->getSchema();
			if (!isset($this->availableTables[$tableName])) {
				$invalidTables[] = $tableName;
			}
		}

		// Recurse into child expressions if the object has methods to get them
		if (is_object($expr)) {
			// Try common methods that return sub-expressions
			$methods = ['getLeft', 'getRight', 'getExpression', 'getArguments'];
			foreach ($methods as $method) {
				if (method_exists($expr, $method)) {
					$subExpr = $expr->$method();
					if ($subExpr !== null) {
						if (is_array($subExpr)) {
							foreach ($subExpr as $item) {
								$this->extractQualifiedNamesRecursive($item, $invalidTables);
							}
						} else {
							$this->extractQualifiedNamesRecursive($subExpr, $invalidTables);
						}
					}
				}
			}
		}
	}

	/**
	 * Extract qualified names from JOIN conditions
	 *
	 * @param array<string> $invalidTables
	 */
	private function extractQualifiedNamesFromJoins(
		object $tableRef,
		array &$invalidTables,
	): void {
		if ($tableRef instanceof InnerJoin || $tableRef instanceof OuterJoin) {
			// Check the JOIN condition
			$condition = $tableRef->getCondition();
			if ($condition instanceof \SqlFtw\Sql\Expression\RootNode) {
				$this->extractQualifiedNamesRecursive($condition, $invalidTables);
			}

			// Recurse into left and right sides
			$this->extractQualifiedNamesFromJoins($tableRef->getLeft(), $invalidTables);
			$this->extractQualifiedNamesFromJoins($tableRef->getRight(), $invalidTables);
		}
	}

	/**
	 * Find all line offsets where a table reference appears in the SQL string
	 * Returns array of line offsets for each occurrence
	 *
	 * @return array<int>
	 */
	private function findAllTableReferenceOffsets(
		string $sql,
		string $tableName,
	): array {
		$offsets = [];

		// Search for ALL occurrences of the table name followed by a dot (qualified reference)
		$pattern = '/\b' . preg_quote($tableName, '/') . '\s*\./i';

		if (preg_match_all($pattern, $sql, $matches, PREG_OFFSET_CAPTURE) > 0) {
			foreach ($matches[0] as $match) {
				$matchPosition = $match[1];

				// Calculate line offset based on newlines before the match
				$beforeMatch = substr($sql, 0, $matchPosition);
				$offsets[] = substr_count($beforeMatch, "\n");
			}
		}

		// If we can't find any (shouldn't happen), return [0]
		return $offsets !== [] ? $offsets : [0];
	}
}
