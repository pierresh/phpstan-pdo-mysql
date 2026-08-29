<?php declare(strict_types=1);

namespace Pierresh\PhpStanPdoMysql\SqlLinter;

/**
 * node-sql-parser adapter for SQL syntax validation.
 *
 * This adapter shells out to the Node CLI in sql-cli/ which wraps the
 * node-sql-parser npm package. node-sql-parser supports several dialects
 * through one "database" option (transactsql, postgresql, mysql, ...),
 * so this single adapter serves any of them - which dialect is used is
 * just a constructor argument, not a separate adapter class.
 */
class NodeSqlParserAdapter implements SqlLinterInterface
{
	private const MAX_QUERY_LENGTH = 10000;

	private ?bool $isAvailable = null;

	public function __construct(
		private readonly string $dialect,
	) {}

	public function validate(string $sqlQuery): array
	{
		if (mb_strlen($sqlQuery) > self::MAX_QUERY_LENGTH) {
			return [];
		}

		if (!$this->isAvailable()) {
			return [];
		}

		$sanitizedSql = $this->replacePlaceholdersOutsideQuotes($sqlQuery);

		$output = $this->runCli($sanitizedSql);

		if ($output === null) {
			return [];
		}

		return $this->parseCliOutput($output);
	}

	private function runCli(string $sqlQuery): ?string
	{
		$payload = json_encode([
			'dialect' => $this->dialect,
			'sql' => $sqlQuery,
		]);

		if ($payload === false) {
			return null;
		}

		$descriptorSpec = [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		];

		$process = proc_open(
			['node', $this->getCliScriptPath()],
			$descriptorSpec,
			$pipes,
		);

		if (!is_resource($process)) {
			return null;
		}

		fwrite($pipes[0], $payload);
		fclose($pipes[0]);

		$stdout = stream_get_contents($pipes[1]);
		fclose($pipes[1]);
		fclose($pipes[2]);

		proc_close($process);

		return $stdout === false ? null : $stdout;
	}

	/**
	 * @return array<array{message: string, sqlLine: int|null}>
	 */
	private function parseCliOutput(string $output): array
	{
		/** @var array{errors?: array<array{message: string, line: int|null}>}|null $decoded */
		$decoded = json_decode($output, true);

		if (!is_array($decoded) || !isset($decoded['errors'])) {
			return [];
		}

		$errors = [];
		foreach ($decoded['errors'] as $error) {
			$errors[] = [
				'message' => $error['message'],
				'sqlLine' => $error['line'],
			];
		}

		return $errors;
	}

	/**
	 * Replace PDO placeholders with literals, but only outside of quoted strings.
	 *
	 * Duplicated from SqlFtwAdapter: both adapters need the exact same
	 * preprocessing (PDO allows placeholder names starting with digits,
	 * e.g. :5min_ago, which neither underlying parser accepts natively).
	 */
	private function replacePlaceholdersOutsideQuotes(string $sql): string
	{
		$result = '';
		$length = strlen($sql);
		$i = 0;
		$inSingleQuote = false;
		$inDoubleQuote = false;

		while ($i < $length) {
			$char = $sql[$i];

			if ($char === "'" && !$inDoubleQuote) {
				$result .= $char;
				$i++;
				$inSingleQuote = !$inSingleQuote;
				continue;
			}

			if ($char === '"' && !$inSingleQuote) {
				$result .= $char;
				$i++;
				$inDoubleQuote = !$inDoubleQuote;
				continue;
			}

			if (
				($inSingleQuote || $inDoubleQuote)
				&& $char === '\\'
				&& ($i + 1) < $length
			) {
				$result .= $char . $sql[$i + 1];
				$i += 2;
				continue;
			}

			if (!$inSingleQuote && !$inDoubleQuote && $char === ':') {
				$placeholderLength = 1;
				while (($i + $placeholderLength) < $length) {
					$nextChar = $sql[$i + $placeholderLength];
					if (!ctype_alnum($nextChar) && $nextChar !== '_') {
						break;
					}

					$placeholderLength++;
				}

				if ($placeholderLength > 1) {
					$result .= '1';
					$i += $placeholderLength;
					continue;
				}
			}

			$result .= $char;
			$i++;
		}

		return $result;
	}

	private function getCliScriptPath(): string
	{
		return __DIR__ . '/../../sql-cli/dist/sql-lint.js';
	}

	public function isAvailable(): bool
	{
		if ($this->isAvailable !== null) {
			return $this->isAvailable;
		}

		if (mb_strlen((string) shell_exec('which node')) === 0) {
			return $this->isAvailable = false;
		}

		return $this->isAvailable = is_file($this->getCliScriptPath());
	}
}
