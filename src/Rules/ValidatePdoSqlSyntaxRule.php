<?php declare(strict_types=1);

namespace Pierresh\PhpStanPdoMysql\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PhpMyAdmin\SqlParser\Lexer;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Utils\Error as ParserError;

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
	private function extractSqlVariables(ClassMethod $method): array
	{
		$sqlVariables = [];

		foreach ($method->getStmts() ?? [] as $stmt) {
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
	private function extractSqlVariablesRecursive(Node $node, array &$sqlVariables): void
	{
		// Look for assignments: $var = "SQL string"
		if ($node instanceof Node\Stmt\Expression && $node->expr instanceof Assign) {
			$assign = $node->expr;

			// Early bailout: only process simple variable assignments
			if (!($assign->var instanceof Variable && is_string($assign->var->name))) {
				// Continue to recurse even if this isn't a variable assignment
			} elseif ($assign->expr instanceof String_) {
				// Only process if right side is a string
				$sql = $assign->expr->value;

				// Simple heuristic: if it contains SQL keywords, consider it SQL
				if ($this->looksLikeSQL($sql)) {
					$varName = $assign->var->name;
					$sqlVariables[$varName] = [
						'sql' => $sql,
						'line' => $node->getStartLine(),
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
	private function findPrepareQueryCalls(Node $node, array $sqlVariables, array &$errors): void
	{
		// Check if this is a prepare() or query() call
		if ($node instanceof Node\Stmt\Expression && $node->expr instanceof MethodCall) {
			$methodCall = $node->expr;

			if ($methodCall->name instanceof Node\Identifier) {
				$methodName = $methodCall->name->toString();

				if ($methodName === 'prepare' || $methodName === 'query') {
					if (count($methodCall->getArgs()) > 0) {
						$firstArg = $methodCall->getArgs()[0]->value;

						// Case 1: Direct string literal
						if ($firstArg instanceof String_) {
							$errors = array_merge(
								$errors,
								$this->validateSqlQuery($firstArg->value, $node->getStartLine(), $methodName)
							);
						}
						// Case 2: Variable reference
						elseif ($firstArg instanceof Variable && is_string($firstArg->name)) {
							$varName = $firstArg->name;
							if (isset($sqlVariables[$varName])) {
								$errors = array_merge(
									$errors,
									$this->validateSqlQuery(
										$sqlVariables[$varName]['sql'],
										$node->getStartLine(),
										$methodName
									)
								);
							}
						}
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

					if ($methodName === 'prepare' || $methodName === 'query') {
						if (count($methodCall->getArgs()) > 0) {
							$firstArg = $methodCall->getArgs()[0]->value;

							// Case 1: Direct string literal
							if ($firstArg instanceof String_) {
								$errors = array_merge(
									$errors,
									$this->validateSqlQuery($firstArg->value, $node->getStartLine(), $methodName)
								);
							}
							// Case 2: Variable reference
							elseif ($firstArg instanceof Variable && is_string($firstArg->name)) {
								$varName = $firstArg->name;
								if (isset($sqlVariables[$varName])) {
									$errors = array_merge(
										$errors,
										$this->validateSqlQuery(
											$sqlVariables[$varName]['sql'],
											$node->getStartLine(),
											$methodName
										)
									);
								}
							}
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

	/**
	 * Validate SQL query and return errors
	 *
	 * @return array<\PHPStan\Rules\RuleError>
	 */
	private function validateSqlQuery(string $sqlQuery, int $line, string $methodName): array
	{
		// Check if phpmyadmin/sql-parser is installed
		if (!class_exists(Lexer::class)) {
			// Silently skip if not installed - don't show warnings
			return [];
		}

		// Skip validation for very long queries to save resources
		if (mb_strlen($sqlQuery) > 10000) {
			return [];
		}

		$errors = [];

		// Parse the SQL query
		$lexer = new Lexer($sqlQuery);
		$parser = new Parser($lexer->list);

		// Get parsing errors
		$parserErrors = ParserError::get([$lexer, $parser]);

		foreach ($parserErrors as $error) {
			// $error is an array: [message, token, position, ...]
			$message = (string) $error[0];
			$token = (string) ($error[2] ?? '');

			$errorMessage = $message;
			if ($token !== '') {
				$errorMessage .= sprintf(' (near %s)', $token);
			}

			$errors[] = RuleErrorBuilder::message(
				sprintf(
					'SQL syntax error in %s(): %s',
					$methodName,
					$errorMessage
				)
			)->line($line)->build();
		}

		return $errors;
	}
}
