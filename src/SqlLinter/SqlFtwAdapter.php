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

		try {
			$commands = $this->parseQuery($sqlQuery);
			return $this->extractErrors($commands);
		} catch (\Exception $exception) {
			// If parsing completely fails, report the error
			return [
				[
					'message' => $exception->getMessage(),
					'sqlLine' => null,
				],
			];
		}
	}

	/**
	 * Parse SQL query using SQLFTW parser
	 *
	 * @return iterable<mixed>
	 */
	private function parseQuery(string $sqlQuery): iterable
	{
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

		return $parser->parse($sanitizedSql ?? $sqlQuery);
	}

	/**
	 * Extract errors from parsed commands
	 *
	 * @param iterable<mixed> $commands
	 * @return array<array{message: string, sqlLine: int|null}>
	 */
	private function extractErrors(iterable $commands): array
	{
		$errors = [];

		foreach ($commands as $command) {
			if ($command instanceof InvalidCommand) {
				$errors[] = $this->buildErrorFromInvalidCommand($command);
			}
		}

		return $errors;
	}

	/**
	 * Build error array from InvalidCommand
	 *
	 * @return array{message: string, sqlLine: int|null}
	 */
	private function buildErrorFromInvalidCommand(InvalidCommand $invalidCommand): array
	{
		$throwable = $invalidCommand->getException();
		$errorMessage = $throwable->getMessage();
		$sqlLine = $this->extractSqlLineNumber($throwable, $errorMessage);

		return [
			'message' => $this->cleanErrorMessage($errorMessage),
			'sqlLine' => $sqlLine,
		];
	}

	/**
	 * Extract SQL line number from exception
	 */
	private function extractSqlLineNumber(
		\Throwable $throwable,
		string $errorMessage,
	): null|int {
		if (in_array(
			preg_match('/at position (\d+)/', $errorMessage, $matches),
			[0, false],
			true,
		)) {
			return null;
		}

		$tokenIndex = (int) $matches[1];
		// @phpstan-ignore-next-line - InvalidCommand always throws InvalidTokenException which has getTokenList()
		$tokenList = $throwable->getTokenList();
		$tokens = $tokenList->getTokens();

		if (isset($tokens[$tokenIndex])) {
			return $tokens[$tokenIndex]->row;
		}

		if (count($tokens) > 0) {
			// If token index is out of bounds (e.g., "end of query"),
			// use the last token's row
			$lastToken = end($tokens);
			return $lastToken->row;
		}

		return null;
	}

	/**
	 * Clean up error message by removing SQL context
	 */
	private function cleanErrorMessage(string $errorMessage): string
	{
		if (!str_contains($errorMessage, ' at position ')) {
			return $errorMessage;
		}

		return (
			preg_replace('/ at position \d+ in:.*$/s', '.', $errorMessage)
			?? $errorMessage
		);
	}

	public function isAvailable(): bool
	{
		return class_exists(SqlFtwParser::class);
	}
}
