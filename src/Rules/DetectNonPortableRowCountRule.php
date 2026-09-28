<?php declare(strict_types=1);

namespace Pierresh\PhpStanPdoMysql\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\InterpolatedString;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\InterpolatedStringPart;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\PrettyPrinter\Standard;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * This rule detects rowCount() checks on SELECT statements that do not work
 * the same on every database.
 *
 * On SQL Server (pdo_sqlsrv), rowCount() after a SELECT returns -1 when there
 * are rows and 0 when there are none, so only comparisons with 0 are portable:
 * - rowCount() === 0, rowCount() == 0, !rowCount() → no rows
 * - rowCount() !== 0, rowCount() != 0, if (rowCount()) → rows found
 *
 * Other comparisons, like rowCount() > 0 or rowCount() === 1, are flagged.
 * Statements whose SQL is not a SELECT (UPDATE, DELETE...) or cannot be
 * resolved statically are not checked: rowCount() is portable for them.
 *
 * @implements Rule<Class_>
 */
class DetectNonPortableRowCountRule implements Rule
{
	private const COMPARISONS = [
		BinaryOp\Identical::class,
		BinaryOp\NotIdentical::class,
		BinaryOp\Equal::class,
		BinaryOp\NotEqual::class,
		BinaryOp\Greater::class,
		BinaryOp\GreaterOrEqual::class,
		BinaryOp\Smaller::class,
		BinaryOp\SmallerOrEqual::class,
	];

	private readonly NodeFinder $nodeFinder;

	private readonly Standard $standard;

	public function __construct()
	{
		$this->nodeFinder = new NodeFinder();
		$this->standard = new Standard();
	}

	public function getNodeType(): string
	{
		return Class_::class;
	}

	/** @return list<IdentifierRuleError> */
	public function processNode(Node $node, Scope $scope): array
	{
		$errors = [];
		$propertySqls = null;

		foreach ($node->getMethods() as $classMethod) {
			$comparisons = $this->findRowCountComparisons($classMethod);

			// Early bailout: most methods have no rowCount() comparison
			if ($comparisons === []) {
				continue;
			}

			$propertySqls ??= $this->extractPropertySqls($node);
			$localSqls = $this->extractLocalSqls($classMethod);

			foreach ($comparisons as [$comparison, $rowCountCall]) {
				$sqlInfo = $this->resolveStatementSql($rowCountCall, $comparison->getStartLine(), $localSqls, $propertySqls);

				if ($sqlInfo === null || !$this->isSelect($sqlInfo['sql'])) {
					continue;
				}

				$errors[] = RuleErrorBuilder::message(sprintf(
					'Not portable to SQL Server: %s on a SELECT (line %d). On SQL Server, rowCount() after a SELECT returns -1 when there are rows. Use fetch() and check the result against false instead.',
					$this->standard->prettyPrintExpr($comparison),
					$sqlInfo['line'],
				))
					->line($comparison->getStartLine())
					->identifier('pdoSql.nonPortableRowCount')
					->build();
			}
		}

		return $errors;
	}

	/**
	 * Find comparisons involving rowCount() that are not a comparison with 0
	 *
	 * @return list<array{BinaryOp, MethodCall}> The comparison and its rowCount() call
	 */
	private function findRowCountComparisons(ClassMethod $classMethod): array
	{
		$comparisons = [];

		/** @var list<BinaryOp> $binaryOps */
		$binaryOps = $this->nodeFinder->find(
			$classMethod->getStmts() ?? [],
			static fn(Node $node): bool => in_array($node::class, self::COMPARISONS, true),
		);

		foreach ($binaryOps as $binaryOp) {
			[$rowCountCall, $other] = $this->isRowCountCall($binaryOp->left)
				? [$binaryOp->left, $binaryOp->right]
				: [$binaryOp->right, $binaryOp->left];

			if (!$rowCountCall instanceof MethodCall || !$this->isRowCountCall($rowCountCall)) {
				continue;
			}

			// rowCount() ===/==/!==/!= 0 is portable
			$isEquality = $binaryOp instanceof BinaryOp\Identical
				|| $binaryOp instanceof BinaryOp\NotIdentical
				|| $binaryOp instanceof BinaryOp\Equal
				|| $binaryOp instanceof BinaryOp\NotEqual;

			if ($isEquality && $other instanceof Int_ && $other->value === 0) {
				continue;
			}

			$comparisons[] = [$binaryOp, $rowCountCall];
		}

		return $comparisons;
	}

	private function isRowCountCall(Node\Expr $expr): bool
	{
		return $expr instanceof MethodCall
			&& $expr->name instanceof Node\Identifier
			&& $expr->name->toString() === 'rowCount';
	}

	/**
	 * Find the SQL of the statement rowCount() is called on
	 *
	 * @param array<string, list<array{sql: string|null, line: int}>> $localSqls
	 * @param array<string, list<array{sql: string|null, line: int}>> $propertySqls
	 * @return array{sql: string, line: int}|null
	 */
	private function resolveStatementSql(
		MethodCall $methodCall,
		int $line,
		array $localSqls,
		array $propertySqls,
	): ?array {
		$target = $this->getStatementName($methodCall->var);
		if ($target === null) {
			return null;
		}

		// Local statement: the last assignment before the comparison
		if (isset($localSqls[$target])) {
			$latest = null;
			foreach ($localSqls[$target] as $assignment) {
				if ($assignment['line'] <= $line) {
					$latest = $assignment;
				}
			}

			return $latest !== null && $latest['sql'] !== null
				? ['sql' => $latest['sql'], 'line' => $latest['line']]
				: null;
		}

		// Property statement: only when every assignment is resolved and a SELECT
		$first = null;
		foreach ($propertySqls[$target] ?? [] as $assignment) {
			if ($assignment['sql'] === null || !$this->isSelect($assignment['sql'])) {
				return null;
			}

			$first ??= ['sql' => $assignment['sql'], 'line' => $assignment['line']];
		}

		return $first;
	}

	/**
	 * Name a statement expression: 'stmt' for $stmt, '$this->stmt' for $this->stmt
	 */
	private function getStatementName(Node\Expr $expr): ?string
	{
		if ($expr instanceof Variable && is_string($expr->name)) {
			return $expr->name;
		}

		if (
			$expr instanceof PropertyFetch
			&& $expr->var instanceof Variable
			&& $expr->var->name === 'this'
			&& $expr->name instanceof Node\Identifier
		) {
			return '$this->' . $expr->name->toString();
		}

		return null;
	}

	/**
	 * Statements assigned in this method: $stmt = $db->prepare(...) / $db->query(...)
	 *
	 * @return array<string, list<array{sql: string|null, line: int}>>
	 */
	private function extractLocalSqls(ClassMethod $classMethod): array
	{
		$sqls = [];
		foreach ($this->extractStatementAssignments($classMethod) as [$name, $sql, $line]) {
			if (!str_starts_with($name, '$this->')) {
				$sqls[$name][] = ['sql' => $sql, 'line' => $line];
			}
		}

		return $sqls;
	}

	/**
	 * Statements assigned to properties in any method: $this->stmt = $db->prepare(...)
	 *
	 * @return array<string, list<array{sql: string|null, line: int}>>
	 */
	private function extractPropertySqls(Class_ $class): array
	{
		$sqls = [];
		foreach ($class->getMethods() as $classMethod) {
			foreach ($this->extractStatementAssignments($classMethod) as [$name, $sql, $line]) {
				if (str_starts_with($name, '$this->')) {
					$sqls[$name][] = ['sql' => $sql, 'line' => $line];
				}
			}
		}

		return $sqls;
	}

	/**
	 * @return list<array{string, string|null, int}> Statement name, SQL (null when unresolved), line
	 */
	private function extractStatementAssignments(ClassMethod $classMethod): array
	{
		$stmts = $classMethod->getStmts() ?? [];

		/** @var list<Assign> $assigns */
		$assigns = $this->nodeFinder->findInstanceOf($stmts, Assign::class);

		// SQL strings assigned to variables, e.g. $sql = 'SELECT ...'
		$sqlVariables = [];
		foreach ($assigns as $assign) {
			$sql = $this->resolveString($assign->expr, []);
			if ($sql !== null && $assign->var instanceof Variable && is_string($assign->var->name)) {
				$sqlVariables[$assign->var->name] = $sql;
			}
		}

		$assignments = [];
		foreach ($assigns as $assign) {
			$name = $this->getStatementName($assign->var);

			if (
				$name === null
				|| !$assign->expr instanceof MethodCall
				|| !$assign->expr->name instanceof Node\Identifier
				|| !in_array($assign->expr->name->toString(), ['prepare', 'query'], true)
				|| $assign->expr->getArgs() === []
			) {
				continue;
			}

			$sql = $this->resolveString($assign->expr->getArgs()[0]->value, $sqlVariables);
			$assignments[] = [$name, $sql, $assign->getStartLine()];
		}

		return $assignments;
	}

	/**
	 * @param array<string, string> $sqlVariables
	 */
	private function resolveString(Node\Expr $expr, array $sqlVariables): ?string
	{
		if ($expr instanceof String_) {
			return $expr->value;
		}

		if ($expr instanceof InterpolatedString) {
			$sql = '';
			foreach ($expr->parts as $part) {
				if ($part instanceof InterpolatedStringPart) {
					$sql .= $part->value;
				}
			}

			return $sql;
		}

		if ($expr instanceof Variable && is_string($expr->name)) {
			return $sqlVariables[$expr->name] ?? null;
		}

		return null;
	}

	/**
	 * Check if the SQL is a SELECT (or a WITH ... SELECT), ignoring leading comments
	 */
	private function isSelect(string $sql): bool
	{
		$sql = (string) preg_replace('/^(?:\s+|--[^\n]*(?:\n|$)|\/\*.*?\*\/|\()+/s', '', $sql);

		return (bool) preg_match('/^(?:SELECT|WITH)\b/i', $sql);
	}
}
