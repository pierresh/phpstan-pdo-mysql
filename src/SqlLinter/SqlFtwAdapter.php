<?php declare(strict_types=1);

namespace Pierresh\PhpStanPdoMysql\SqlLinter;

use SqlFtw\Parser\Parser as SqlFtwParser;
use SqlFtw\Parser\ParserConfig;
use SqlFtw\Parser\InvalidCommand;
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
			$config = new ParserConfig($platform);
			$session = new Session($platform);
			$parser = new SqlFtwParser($config, $session);

			// Temporarily replace PDO-style placeholders (:param) with valid literals
			// to avoid syntax errors from the parser
			$sanitizedSql = preg_replace('/:([a-zA-Z_][a-zA-Z0-9_]*)/', "'__PLACEHOLDER__'", $sqlQuery);

			$commands = $parser->parse($sanitizedSql ?? $sqlQuery);

			// Check each command for syntax errors
			foreach ($commands as $command) {
				if ($command instanceof InvalidCommand) {
					$exception = $command->getException();
					$errorMessage = $exception->getMessage();

					// Clean up the error message - remove the SQL context for brevity
					if (strpos($errorMessage, ' at position ') !== false) {
						$errorMessage = preg_replace('/ at position \d+ in:.*$/s', '.', $errorMessage);
					}

					$errors[] = $errorMessage;
				}
			}
		} catch (\Exception $e) {
			// If parsing completely fails, report the error
			$errors[] = $e->getMessage();
		}

		return $errors;
	}

	public function isAvailable(): bool
	{
		return class_exists(SqlFtwParser::class);
	}
}
