<?php declare(strict_types=1);

namespace Pierresh\PhpStanPdoMysql\SqlLinter;

use SqlFtw\Parser\InvalidCommand;
use SqlFtw\Parser\Parser as SqlFtwParser;
use SqlFtw\Parser\ParserConfig;
use SqlFtw\Platform\Platform;
use SqlFtw\Session\Session;

/**
 * SQLFTW adapter for SQL syntax validation.
 *
 * This adapter uses the SQLFTW library to validate MySQL syntax.
 * SQLFTW is a strict parser that catches syntax errors like trailing commas.
 */
class SqlFtwAdapter implements SqlLinterInterface
{
	private const MAX_QUERY_LENGTH = 10000;

	public function validate(string $sqlQuery): array
	{
		// Skip validation for very long queries to save resources
		if (mb_strlen($sqlQuery) > self::MAX_QUERY_LENGTH) {
			return [];
		}

		$errors = [];

		try {
			// Parse the SQL query using SQLFTW
			$platform = Platform::get(Platform::MYSQL, '8.0');
			$parserConfig = new ParserConfig($platform);
			$session = new Session($platform);
			$parser = new SqlFtwParser($parserConfig, $session);

			// Temporarily replace PDO-style placeholders (:param) with valid literals
			// to avoid syntax errors from the parser
			$sanitizedSql = preg_replace(
				'/:([a-zA-Z_]\w*)/',
				"'__PLACEHOLDER__'",
				$sqlQuery,
			);

			$commands = $parser->parse($sanitizedSql ?? $sqlQuery);

			// Check each command for syntax errors
			foreach ($commands as $command) {
				if ($command instanceof InvalidCommand) {
					$exception = $command->getException();
					$errorMessage = $exception->getMessage();
					$sqlLine = null;

					// Try to extract the SQL line number from the error token
					if (preg_match('/at position (\d+)/', $errorMessage, $matches)) {
						$tokenIndex = (int) $matches[1];
						// @phpstan-ignore-next-line - InvalidCommand always throws InvalidTokenException which has getTokenList()
						$tokenList = $exception->getTokenList();
						$tokens = $tokenList->getTokens();

						if (isset($tokens[$tokenIndex])) {
							$sqlLine = $tokens[$tokenIndex]->row;
						} elseif (count($tokens) > 0) {
							// If token index is out of bounds (e.g., "end of query"),
							// use the last token's row
							$lastToken = end($tokens);
							$sqlLine = $lastToken->row;
						}
					}

					// Clean up the error message - remove the SQL context for brevity
					if (str_contains($errorMessage, ' at position ')) {
						$errorMessage =
							preg_replace('/ at position \d+ in:.*$/s', '.', $errorMessage)
							?? $errorMessage;
					}

					$errors[] = [
						'message' => $errorMessage,
						'sqlLine' => $sqlLine,
					];
				}
			}
		} catch (\Exception $exception) {
			// If parsing completely fails, report the error
			$errors[] = [
				'message' => $exception->getMessage(),
				'sqlLine' => null,
			];
		}

		return $errors;
	}

	public function isAvailable(): bool
	{
		return class_exists(SqlFtwParser::class);
	}
}
