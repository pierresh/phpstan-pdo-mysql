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

/**
 * This rule detects MySQL-specific SQL syntax that has portable alternatives.
 * It helps maintain database-agnostic code by suggesting standard SQL alternatives.
 *
 * Currently detects:
 * - IFNULL() -> COALESCE()
 * - IF() -> CASE WHEN
 * - NOW() -> CURRENT_TIMESTAMP
 * - CURDATE() -> CURRENT_DATE
 * - LIMIT offset, count -> LIMIT count OFFSET offset
 *
 * @implements Rule<ClassMethod>
 */
class DetectMySqlSpecificSyntaxRule implements Rule
{
	/**
	 * Map of MySQL-specific functions to their portable alternatives
	 *
	 * @var array<string, string>
	 */
	private const FUNCTION_ALTERNATIVES = [
		'IFNULL' => 'Use COALESCE() instead of IFNULL() for database portability',
		'IF' => 'Use CASE WHEN instead of IF() for database portability',
		'NOW' => 'Bind current datetime to a PHP variable instead of NOW() for database portability',
		'CURDATE' => 'Bind current date to a PHP variable instead of CURDATE() for database portability',
	];

	public function getNodeType(): string
	{
		return ClassMethod::class;
	}

	public function processNode(Node $node, Scope $scope): array
	{
		$errors = [];

		// First pass: collect all variable assignments with SQL strings
		$sqlVariables = $this->extractSqlVariables($node);

		// Second pass: find all prepare()/query() calls and check for MySQL-specific syntax
		$this->findAndCheckSqlCalls($node, $sqlVariables, $errors);

		return $errors;
	}

	/**
	 * Extract SQL query strings assigned to variables
	 *
	 * @return array<string, array{sql: string, line: int}> Variable name => [sql, line]
	 */
	private function extractSqlVariables(ClassMethod $classMethod): array
	{
		$sqlVariables = [];

		foreach ($classMethod->getStmts() ?? [] as $stmt) {
			$this->extractSqlVariablesRecursive($stmt, $sqlVariables);
		}

		return $sqlVariables;
	}

	/**
	 * Recursively extract SQL strings from variable assignments
	 *
	 * @param array<string, array{sql: string, line: int}> &$sqlVariables
	 */
	private function extractSqlVariablesRecursive(
		Node $node,
		array &$sqlVariables,
	): void {
		// Check if this is an assignment
		if (
			$node instanceof Node\Stmt\Expression
			&& $node->expr instanceof Assign
			&& $node->expr->var instanceof Variable
			&& is_string($node->expr->var->name)
			&& $node->expr->expr instanceof String_
		) {
			$sql = $node->expr->expr->value;
			if ($this->looksLikeSql($sql)) {
				$sqlVariables[$node->expr->var->name] = [
					'sql' => $sql,
					'line' => $node->getStartLine(),
				];
			}
		}

		// Recurse into child nodes
		foreach ($node->getSubNodeNames() as $name) {
			$subNode = $node->{$name};
			if ($subNode instanceof Node) {
				$this->extractSqlVariablesRecursive($subNode, $sqlVariables);
			} elseif (is_array($subNode)) {
				foreach ($subNode as $item) {
					if ($item instanceof Node) {
						$this->extractSqlVariablesRecursive($item, $sqlVariables);
					}
				}
			}
		}
	}

	/**
	 * Find prepare()/query() calls and check for MySQL-specific syntax
	 *
	 * @param array<string, array{sql: string, line: int}> $sqlVariables
	 * @param array<\PHPStan\Rules\RuleError> &$errors
	 */
	private function findAndCheckSqlCalls(
		Node $node,
		array $sqlVariables,
		array &$errors,
	): void {
		// Check if this is a prepare() or query() call
		if (
			$node instanceof MethodCall
			&& $node->name instanceof Node\Identifier
			&& in_array($node->name->toString(), ['prepare', 'query'], true)
			&& $node->getArgs() !== []
		) {
			$firstArg = $node->getArgs()[0]->value;
			$sql = null;
			$line = $node->getStartLine();

			// Direct string literal
			if ($firstArg instanceof String_) {
				$sql = $firstArg->value;
			} elseif ($firstArg instanceof Variable && is_string($firstArg->name)) {
				// Variable reference
				if (isset($sqlVariables[$firstArg->name])) {
					$sql = $sqlVariables[$firstArg->name]['sql'];
					$line = $sqlVariables[$firstArg->name]['line'];
				}
			}

			if ($sql !== null) {
				$this->checkForMySqlSpecificSyntax($sql, $line, $errors);
			}
		}

		// Recurse into child nodes
		foreach ($node->getSubNodeNames() as $name) {
			$subNode = $node->{$name};
			if ($subNode instanceof Node) {
				$this->findAndCheckSqlCalls($subNode, $sqlVariables, $errors);
			} elseif (is_array($subNode)) {
				foreach ($subNode as $item) {
					if ($item instanceof Node) {
						$this->findAndCheckSqlCalls($item, $sqlVariables, $errors);
					}
				}
			}
		}
	}

	/**
	 * Check SQL for MySQL-specific syntax
	 *
	 * @param array<\PHPStan\Rules\RuleError> &$errors
	 */
	private function checkForMySqlSpecificSyntax(
		string $sql,
		int $line,
		array &$errors,
	): void {
		// Check for MySQL-specific functions
		foreach (self::FUNCTION_ALTERNATIVES as $function => $message) {
			// Match function name followed by opening parenthesis
			// Use word boundary to avoid matching partial names
			$pattern = '/\b' . $function . '\s*\(/i';

			if (preg_match($pattern, $sql, $matches, PREG_OFFSET_CAPTURE) === 1) {
				$matchPosition = $matches[0][1];
				$lineOffset = $this->calculateLineOffset($sql, $matchPosition);

				$errors[] = RuleErrorBuilder::message($message)
					->line($line + $lineOffset)
					->identifier('pdoSql.mySqlSpecific')
					->build();
			}
		}

		// Check for MySQL-specific LIMIT syntax: LIMIT offset, count
		// Standard SQL uses: LIMIT count OFFSET offset
		if (
			preg_match('/\bLIMIT\s+\d+\s*,\s*\d+/i', $sql, $matches, PREG_OFFSET_CAPTURE)
			=== 1
		) {
			$matchPosition = $matches[0][1];
			$lineOffset = $this->calculateLineOffset($sql, $matchPosition);

			$errors[] = RuleErrorBuilder::message(
				'Use LIMIT count OFFSET offset instead of LIMIT offset, count for database portability',
			)
				->line($line + $lineOffset)
				->identifier('pdoSql.mySqlSpecific')
				->build();
		}
	}

	/**
	 * Calculate the line offset based on newlines before the match position
	 */
	private function calculateLineOffset(string $sql, int $position): int
	{
		$beforeMatch = substr($sql, 0, $position);

		return substr_count($beforeMatch, "\n");
	}

	/**
	 * Simple heuristic to detect if a string looks like SQL
	 */
	private function looksLikeSql(string $str): bool
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
			if (str_starts_with($upperStr, $sqlKeyword)) {
				return true;
			}
		}

		return false;
	}
}
