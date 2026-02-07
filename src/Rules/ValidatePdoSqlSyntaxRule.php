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
use Pierresh\PhpStanPdoMysql\SqlLinter\SqlFtwAdapter;
use Pierresh\PhpStanPdoMysql\SqlLinter\SqlLinterInterface;

/**
 * This rule validates SQL syntax in PDO prepare() and query() method calls.
 * It checks all prepare() and query() calls in the codebase for MySQL SQL syntax errors.
 *
 * This rule supports:
 * 1. Direct string literals: $db->prepare("SELECT ...")
 * 2. Variables: $sql = "SELECT ..."; $db->prepare($sql)
 *
 * @implements Rule<ClassMethod>
 */
class ValidatePdoSqlSyntaxRule implements Rule
{
	public function __construct(
		private readonly SqlLinterInterface $sqlLinter,
	) {}

	public function getNodeType(): string
	{
		return ClassMethod::class;
	}

	public function processNode(Node $node, Scope $scope): array
	{
		$errors = [];

		// First pass: collect all variable assignments with SQL strings
		$sqlVariables = $this->extractSqlVariables($node);

		// Second pass: find all prepare()/query() calls and validate
		$this->findPrepareQueryCalls($node, $sqlVariables, $errors);

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
	 * Optimized: Early bailouts for non-matching nodes
	 *
	 * @param array<string, array{sql: string, line: int}> &$sqlVariables
	 */
	private function extractSqlVariablesRecursive(
		Node $node,
		array &$sqlVariables,
	): void {
		$this->processVariableAssignment($node, $sqlVariables);
		$this->recurseIntoChildNodes($node, $sqlVariables);
	}

	/**
	 * Process variable assignment if it's an SQL string
	 *
	 * @param array<string, array{sql: string, line: int}> &$sqlVariables
	 */
	private function processVariableAssignment(
		Node $node,
		array &$sqlVariables,
	): void {
		if (!$this->isExpressionAssignment($node)) {
			return;
		}

		/** @var Node\Stmt\Expression $node */
		/** @var Assign $assign */
		$assign = $node->expr;
		$sqlData = $this->extractSqlFromAssignment($assign, $node->getStartLine());

		if ($sqlData !== null) {
			[$varName, $sql, $line] = $sqlData;
			$sqlVariables[$varName] = [
				'sql' => $sql,
				'line' => $line,
			];
		}
	}

	/**
	 * Check if node is an expression with assignment
	 */
	private function isExpressionAssignment(Node $node): bool
	{
		return $node instanceof Node\Stmt\Expression && $node->expr instanceof Assign;
	}

	/**
	 * Extract SQL from assignment if it's a valid SQL variable assignment
	 *
	 * @return array{string, string, int}|null [varName, sql, line]
	 */
	private function extractSqlFromAssignment(Assign $assign, int $line): ?array
	{
		if (!$this->isSimpleVariableAssignment($assign)) {
			return null;
		}

		if (!$assign->expr instanceof String_) {
			return null;
		}

		$sql = $assign->expr->value;

		if (!$this->looksLikeSQL($sql)) {
			return null;
		}

		/** @var Variable $var */
		$var = $assign->var;
		/** @var string $varName */
		$varName = $var->name;
		return [$varName, $sql, $line];
	}

	/**
	 * Check if assignment is to a simple variable
	 */
	private function isSimpleVariableAssignment(Assign $assign): bool
	{
		return $assign->var instanceof Variable && is_string($assign->var->name);
	}

	/**
	 * Recurse into child nodes for SQL variable extraction
	 *
	 * @param array<string, array{sql: string, line: int}> &$sqlVariables
	 */
	private function recurseIntoChildNodes(Node $node, array &$sqlVariables): void
	{
		foreach ($node->getSubNodeNames() as $subNodeName) {
			$subNode = $node->{$subNodeName}; // @phpstan-ignore property.dynamicName

			if (is_array($subNode)) {
				foreach ($subNode as $item) {
					if ($item instanceof Node) {
						$this->extractSqlVariablesRecursive($item, $sqlVariables);
					}
				}
			} elseif ($subNode instanceof Node) {
				$this->extractSqlVariablesRecursive($subNode, $sqlVariables);
			}
		}
	}

	/**
	 * Find all prepare() and query() calls and validate their SQL
	 *
	 * @param array<string, array{sql: string, line: int}> $sqlVariables
	 * @param array<\PHPStan\Rules\RuleError> &$errors
	 */
	private function findPrepareQueryCalls(
		Node $node,
		array $sqlVariables,
		array &$errors,
	): void {
		$this->processDirectMethodCall($node, $sqlVariables, $errors);
		$this->processAssignmentMethodCall($node, $sqlVariables, $errors);
		$this->recurseIntoChildNodesForValidation($node, $sqlVariables, $errors);
	}

	/**
	 * Process direct prepare() or query() method calls
	 *
	 * @param array<string, array{sql: string, line: int}> $sqlVariables
	 * @param array<\PHPStan\Rules\RuleError> &$errors
	 */
	private function processDirectMethodCall(
		Node $node,
		array $sqlVariables,
		array &$errors,
	): void {
		if (!$this->isDirectMethodCallExpression($node)) {
			return;
		}

		/** @var Node\Stmt\Expression $node */
		/** @var MethodCall $methodCall */
		$methodCall = $node->expr;

		if (!$methodCall->name instanceof Node\Identifier) {
			return;
		}

		$methodName = $methodCall->name->toString();

		if (!$this->isPrepareOrQueryCall($methodName, $methodCall)) {
			return;
		}

		$this->validateMethodCallArgument(
			$methodCall->getArgs()[0]->value,
			$node->getStartLine(),
			$methodName,
			$sqlVariables,
			$errors,
		);
	}

	/**
	 * Check if node is a direct method call expression
	 */
	private function isDirectMethodCallExpression(Node $node): bool
	{
		return (
			$node instanceof Node\Stmt\Expression
			&& $node->expr instanceof MethodCall
		);
	}

	/**
	 * Check if method call is prepare() or query()
	 */
	private function isPrepareOrQueryCall(
		string $methodName,
		MethodCall $methodCall,
	): bool {
		return (
			($methodName === 'prepare' || $methodName === 'query')
			&& $methodCall->getArgs() !== []
		);
	}

	/**
	 * Validate the SQL argument from a method call
	 *
	 * @param array<string, array{sql: string, line: int}> $sqlVariables
	 * @param array<\PHPStan\Rules\RuleError> &$errors
	 */
	private function validateMethodCallArgument(
		Node\Expr $expr,
		int $line,
		string $methodName,
		array $sqlVariables,
		array &$errors,
	): void {
		// Case 1: Direct string literal
		if ($expr instanceof String_) {
			$errors = array_merge($errors, $this->validateSqlQuery(
				$expr->value,
				$line,
				$methodName,
			));
			return;
		}

		// Case 2: Variable reference
		if (!($expr instanceof Variable && is_string($expr->name))) {
			return;
		}

		$varName = $expr->name;
		if (isset($sqlVariables[$varName])) {
			$errors = array_merge($errors, $this->validateSqlQuery(
				$sqlVariables[$varName]['sql'],
				$line,
				$methodName,
			));
		}
	}

	/**
	 * Process assignment with prepare() or query() method calls
	 *
	 * @param array<string, array{sql: string, line: int}> $sqlVariables
	 * @param array<\PHPStan\Rules\RuleError> &$errors
	 */
	private function processAssignmentMethodCall(
		Node $node,
		array $sqlVariables,
		array &$errors,
	): void {
		if (!$this->isAssignmentWithMethodCall($node)) {
			return;
		}

		/** @var Node\Stmt\Expression $node */
		/** @var Assign $assign */
		$assign = $node->expr;
		/** @var MethodCall $methodCall */
		$methodCall = $assign->expr;

		if (!$methodCall->name instanceof Node\Identifier) {
			return;
		}

		$methodName = $methodCall->name->toString();

		if (!$this->isPrepareOrQueryCall($methodName, $methodCall)) {
			return;
		}

		$this->validateMethodCallArgument(
			$methodCall->getArgs()[0]->value,
			$node->getStartLine(),
			$methodName,
			$sqlVariables,
			$errors,
		);
	}

	/**
	 * Check if node is an assignment with method call
	 */
	private function isAssignmentWithMethodCall(Node $node): bool
	{
		return (
			$node instanceof Node\Stmt\Expression
			&& $node->expr instanceof Assign
			&& $node->expr->expr instanceof MethodCall
		);
	}

	/**
	 * Recurse into child nodes for prepare/query validation
	 *
	 * @param array<string, array{sql: string, line: int}> $sqlVariables
	 * @param array<\PHPStan\Rules\RuleError> &$errors
	 */
	private function recurseIntoChildNodesForValidation(
		Node $node,
		array $sqlVariables,
		array &$errors,
	): void {
		foreach ($node->getSubNodeNames() as $subNodeName) {
			$subNode = $node->{$subNodeName}; // @phpstan-ignore property.dynamicName

			if (is_array($subNode)) {
				foreach ($subNode as $item) {
					if ($item instanceof Node) {
						$this->findPrepareQueryCalls($item, $sqlVariables, $errors);
					}
				}
			} elseif ($subNode instanceof Node) {
				$this->findPrepareQueryCalls($subNode, $sqlVariables, $errors);
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
	 * Validate SQL query and return errors
	 *
	 * @return array<\PHPStan\Rules\RuleError>
	 */
	private function validateSqlQuery(
		string $sqlQuery,
		int $line,
		string $methodName,
	): array {
		// Check if linter is available
		if (!$this->sqlLinter->isAvailable()) {
			// Silently skip if not installed - don't show warnings
			return [];
		}

		$errors = [];

		// Validate the SQL query using the linter
		$linterErrors = $this->sqlLinter->validate($sqlQuery);

		foreach ($linterErrors as $linterError) {
			// Calculate the actual PHP line number based on SQL line
			// $line is the PHP line where the SQL string starts
			// $linterError['sqlLine'] is the line within the SQL string (1-indexed)
			$errorLine = $line;
			if ($linterError['sqlLine'] !== null && $linterError['sqlLine'] > 1) {
				// Add offset for multi-line SQL strings
				// sqlLine 1 = line where SQL starts, sqlLine 2 = line + 1, etc.
				$errorLine = $line + ($linterError['sqlLine'] - 1);
			}

			$errors[] = RuleErrorBuilder::message(sprintf(
				'SQL syntax error in %s(): %s',
				$methodName,
				$linterError['message'],
			))
				->line($errorLine)
				->identifier('pdoSql.sqlSyntax')
				->build();
		}

		return $errors;
	}
}
