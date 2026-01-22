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
use SqlFtw\Sql\Expression\BoolLiteral;
use SqlFtw\Sql\Expression\ComparisonOperator;
use SqlFtw\Sql\Expression\NumericLiteral;
use SqlFtw\Sql\Expression\StringLiteral;

/**
 * This rule detects tautological conditions in SQL queries.
 *
 * Examples of errors it catches:
 * - WHERE 1 = 1 (always true)
 * - WHERE 0 = 0 (always true)
 * - WHERE 'yes' = 'yes' (always true)
 * - WHERE TRUE = TRUE (always true)
 * - WHERE 1 = 0 (always false)
 * - WHERE 'a' = 'b' (always false)
 *
 * These conditions are often left over from development and should be removed.
 *
 * @implements Rule<ClassMethod>
 */
class DetectTautologicalConditionsRule implements Rule
{
	private readonly SqlFtwParser $sqlFtwParser;

	public function __construct()
	{
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

		$sqlQueries = $this->extractSqlQueries($node);

		foreach ($sqlQueries as $sqlQuery) {
			$errors = array_merge($errors, $this->checkForTautologies(
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
		$sqlVariables = $this->extractSqlVariables($classMethod);

		foreach ($classMethod->getStmts() ?? [] as $stmt) {
			$this->findSqlCallsRecursive($stmt, $queries, $sqlVariables);
		}

		return $queries;
	}

	/**
	 * Extract SQL strings assigned to variables
	 *
	 * @return array<string, string>
	 */
	private function extractSqlVariables(ClassMethod $classMethod): array
	{
		$sqlVariables = [];

		foreach ($classMethod->getStmts() ?? [] as $stmt) {
			if ($stmt instanceof Node\Stmt\Expression && $stmt->expr instanceof Assign) {
				$assign = $stmt->expr;

				if (
					$assign->var instanceof Variable
					&& is_string($assign->var->name)
					&& $assign->expr instanceof String_
				) {
					$sql = $assign->expr->value;
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

					if ($firstArg instanceof String_) {
						$sql = $firstArg->value;
					} elseif ($firstArg instanceof Variable && is_string($firstArg->name)) {
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

						if ($firstArg instanceof String_) {
							$sql = $firstArg->value;
						} elseif ($firstArg instanceof Variable && is_string($firstArg->name)) {
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
	 * Quick check to see if SQL might contain a tautology
	 * This is a cheap pre-filter to avoid expensive SQLFTW parsing on most queries
	 */
	private function mightContainTautology(string $sql): bool
	{
		// Check for numeric literal comparisons: "1 = 1", "0 = 0", etc.
		// Pattern: digit(s) followed by = followed by digit(s)
		if (preg_match('/\b(\d+)\s*=\s*(\d+)\b/', $sql) === 1) {
			return true;
		}

		// Check for string literal comparisons: "'x' = 'x'", etc.
		// Pattern: quoted string followed by = followed by quoted string
		if (preg_match("/'\w*'\s*=\s*'\w*'/", $sql) === 1) {
			return true;
		}

		// Check for boolean comparisons: "TRUE = TRUE", "FALSE = FALSE", etc.
		$upperSql = strtoupper($sql);
		return (
			str_contains($upperSql, 'TRUE')
			&& str_contains($upperSql, '=')
			|| str_contains($upperSql, 'FALSE')
			&& str_contains($upperSql, '=')
		);
	}

	/**
	 * Track occurrences of each tautology pattern for line-accurate reporting
	 * @var array<string, int>
	 */
	private array $patternOccurrences = [];

	/**
	 * Cache of pattern line positions to avoid repeated regex scanning
	 * @var array<string, array<int, int>>
	 */
	private array $patternLineCache = [];

	/**
	 * Check SQL query for tautological conditions
	 *
	 * @return array<\PHPStan\Rules\RuleError>
	 */
	private function checkForTautologies(string $sql, int $line): array
	{
		$errors = [];

		$this->patternOccurrences = [];
		$this->patternLineCache = [];

		// Early bailout: skip very long queries for performance
		if (strlen($sql) > 10000) {
			return [];
		}

		// Early bailout: quick check for potential tautologies before expensive parsing
		// Look for patterns like "1 = 1", "'x' = 'x'", "TRUE = TRUE", etc.
		if (!$this->mightContainTautology($sql)) {
			return [];
		}

		if (!class_exists(SqlFtwParser::class)) {
			return [];
		}

		// Replace PDO placeholders with NULL so they're not treated as literals
		// that could create false-positive tautology detection
		$preprocessedSql = preg_replace('/:([a-zA-Z_]\w*)/', 'NULL', $sql);
		if (!is_string($preprocessedSql)) {
			return [];
		}

		try {
			$commands = $this->sqlFtwParser->parse($preprocessedSql);

			foreach ($commands as $command) {
				if ($command instanceof InvalidCommand) {
					continue;
				}

				$selectCommand = null;
				if ($command instanceof SelectCommand) {
					$selectCommand = $command;
				} elseif ($command instanceof InsertSelectCommand) {
					$query = $command->getQuery();
					if ($query instanceof SelectCommand) {
						$selectCommand = $query;
					}
				}

				if (!$selectCommand instanceof SelectCommand) {
					continue;
				}

				$from = $selectCommand->getFrom();
				if ($from instanceof \SqlFtw\Sql\Dml\TableReference\TableReferenceNode) {
					$joinErrors = $this->checkJoinConditions($from, $line, $sql);
					$errors = array_merge($errors, $joinErrors);
				}

				$where = $selectCommand->getWhere();
				if ($where instanceof \SqlFtw\Sql\Expression\ExpressionNode) {
					$whereErrors = $this->checkCondition($where, $line, $sql, 'WHERE');
					$errors = array_merge($errors, $whereErrors);
				}

				$having = $selectCommand->getHaving();
				if ($having instanceof \SqlFtw\Sql\Expression\ExpressionNode) {
					$havingErrors = $this->checkCondition($having, $line, $sql, 'HAVING');
					$errors = array_merge($errors, $havingErrors);
				}
			}
		} catch (\Throwable) {
			return [];
		}

		return $errors;
	}

	/**
	 * Check JOIN conditions for tautologies
	 *
	 * @return array<\PHPStan\Rules\RuleError>
	 */
	private function checkJoinConditions(
		object $tableRef,
		int $baseLine,
		string $originalSql,
	): array {
		$errors = [];

		if ($tableRef instanceof InnerJoin || $tableRef instanceof OuterJoin) {
			$left = $tableRef->getLeft();
			$leftErrors = $this->checkJoinConditions($left, $baseLine, $originalSql);
			$errors = array_merge($errors, $leftErrors);

			$condition = $tableRef->getCondition();
			if ($condition instanceof ComparisonOperator) {
				$error = $this->checkComparisonForTautology(
					$condition,
					$baseLine,
					$originalSql,
					'JOIN',
				);
				if ($error instanceof \PHPStan\Rules\RuleError) {
					$errors[] = $error;
				}
			} elseif ($condition instanceof \SqlFtw\Sql\Expression\ExpressionNode) {
				$conditionErrors = $this->checkCondition(
					$condition,
					$baseLine,
					$originalSql,
					'JOIN',
				);
				$errors = array_merge($errors, $conditionErrors);
			}

			$right = $tableRef->getRight();
			$rightErrors = $this->checkJoinConditions($right, $baseLine, $originalSql);
			$errors = array_merge($errors, $rightErrors);
		}

		return $errors;
	}

	/**
	 * Recursively check conditions for tautologies
	 *
	 * @return array<\PHPStan\Rules\RuleError>
	 */
	private function checkCondition(
		object $expr,
		int $baseLine,
		string $originalSql,
		string $context,
	): array {
		$errors = [];

		if ($expr instanceof ComparisonOperator) {
			$error = $this->checkComparisonForTautology(
				$expr,
				$baseLine,
				$originalSql,
				$context,
			);
			if ($error instanceof \PHPStan\Rules\RuleError) {
				$errors[] = $error;
			}
		}

		if ($expr instanceof BinaryOperator) {
			$left = $expr->getLeft();
			$leftErrors = $this->checkCondition(
				$left,
				$baseLine,
				$originalSql,
				$context,
			);
			$errors = array_merge($errors, $leftErrors);

			$right = $expr->getRight();
			$rightErrors = $this->checkCondition(
				$right,
				$baseLine,
				$originalSql,
				$context,
			);
			$errors = array_merge($errors, $rightErrors);
		}

		return $errors;
	}

	/**
	 * Check if a comparison operator is a tautology (literal = literal)
	 */
	private function checkComparisonForTautology(
		ComparisonOperator $comparisonOperator,
		int $baseLine,
		string $originalSql,
		string $context,
	): null|\PHPStan\Rules\RuleError {
		$rootNode = $comparisonOperator->getLeft();
		$right = $comparisonOperator->getRight();

		$leftValue = $this->getLiteralValue($rootNode);
		$rightValue = $this->getLiteralValue($right);

		if ($leftValue === null || $rightValue === null) {
			return null;
		}

		$operator = $comparisonOperator->getOperator()->getValue();
		if ($operator !== '=') {
			return null;
		}

		$isAlwaysTrue = $leftValue['value'] === $rightValue['value'];
		$resultType = $isAlwaysTrue ? 'always true' : 'always false';

		$pattern = sprintf('%s = %s', $leftValue['display'], $rightValue['display']);

		if (!isset($this->patternOccurrences[$pattern])) {
			$this->patternOccurrences[$pattern] = 0;
		}

		$occurrenceIndex = $this->patternOccurrences[$pattern];
		$this->patternOccurrences[$pattern]++;

		$errorLine = $this->calculateErrorLine(
			$leftValue['display'],
			$rightValue['display'],
			$baseLine,
			$originalSql,
			$occurrenceIndex,
		);

		return RuleErrorBuilder::message(sprintf(
			"Tautological condition in %s clause: '%s = %s' (%s)",
			$context,
			$leftValue['display'],
			$rightValue['display'],
			$resultType,
		))
			->line($errorLine)
			->identifier('pdoSql.tautologicalCondition')
			->build();
	}

	/**
	 * Extract literal value from an expression node
	 *
	 * @return array{value: string, display: string}|null
	 */
	private function getLiteralValue(object $expr): null|array
	{
		if ($expr instanceof NumericLiteral) {
			$value = $expr->getValue();
			return ['value' => $value, 'display' => $value];
		}

		if ($expr instanceof StringLiteral) {
			$value = $expr->asString();
			return ['value' => $value, 'display' => sprintf("'%s'", $value)];
		}

		if ($expr instanceof BoolLiteral) {
			$value = $expr->getValue();
			return ['value' => $value, 'display' => $value];
		}

		return null;
	}

	/**
	 * Calculate the actual PHP line number for an error based on SQL token position
	 */
	private function calculateErrorLine(
		string $leftDisplay,
		string $rightDisplay,
		int $baseLine,
		string $originalSql,
		int $occurrenceIndex,
	): int {
		$pattern = sprintf('%s = %s', $leftDisplay, $rightDisplay);

		if (isset($this->patternLineCache[$pattern][$occurrenceIndex])) {
			return $baseLine + $this->patternLineCache[$pattern][$occurrenceIndex];
		}

		if (!isset($this->patternLineCache[$pattern])) {
			$this->patternLineCache[$pattern] = [];
			$lines = explode("\n", $originalSql);

			$escapedLeft = preg_quote($leftDisplay, '/');
			$escapedRight = preg_quote($rightDisplay, '/');
			$tautologyRegex = '/' . $escapedLeft . '\s*=\s*' . $escapedRight . '/i';

			$occurrence = 0;
			foreach ($lines as $index => $line) {
				if (preg_match($tautologyRegex, $line) === 1) {
					$this->patternLineCache[$pattern][$occurrence] = $index;
					$occurrence++;
				}
			}
		}

		$sqlRow = $this->patternLineCache[$pattern][$occurrenceIndex] ?? 0;

		return $baseLine + $sqlRow;
	}
}
