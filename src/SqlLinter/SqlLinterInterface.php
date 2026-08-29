<?php declare(strict_types=1);

namespace Pierresh\PhpStanPdoMysql\SqlLinter;

/**
 * Interface for SQL syntax validation adapters.
 *
 * This abstraction allows switching between different SQL parsers
 * without changing the PHPStan rule implementation.
 */
interface SqlLinterInterface
{
	/**
	 * Validate SQL query syntax.
	 *
	 * @param string $sqlQuery The SQL query to validate
	 * @return array<array{message: string, sqlLine: int|null}> Array of errors with message and SQL line number (1-indexed, relative to SQL string)
	 */
	public function validate(string $sqlQuery): array;

	/**
	 * Validate multiple SQL queries in one call.
	 *
	 * This exists so adapters that shell out to an external process (e.g. Node)
	 * can batch a whole class's worth of queries into a single invocation instead
	 * of spawning one process per query - in-process adapters can just loop.
	 *
	 * @param list<string> $sqlQueries
	 * @return list<array<array{message: string, sqlLine: int|null}>> One result list per input query, same order
	 */
	public function validateBatch(array $sqlQueries): array;

	/**
	 * Check if the linter library is available.
	 *
	 * @return bool True if the underlying parser library is installed
	 */
	public function isAvailable(): bool;
}
