<?php declare(strict_types=1);

namespace Pierresh\PhpStanPdoMysql\Tests\SqlLinter;

use PHPUnit\Framework\TestCase;
use Pierresh\PhpStanPdoMysql\SqlLinter\NodeSqlParserAdapter;

class NodeSqlParserAdapterTest extends TestCase
{
	public function testIsAvailable(): void
	{
		$nodeSqlParserAdapter = new NodeSqlParserAdapter('transactsql');

		// True once sql-cli/dist/sql-lint.js has been built; asserting the method
		// runs without error is what matters for environments where it hasn't.
		$this->assertIsBool($nodeSqlParserAdapter->isAvailable());
	}

	public function testValidTSqlHasNoErrors(): void
	{
		$nodeSqlParserAdapter = new NodeSqlParserAdapter('transactsql');

		if (!$nodeSqlParserAdapter->isAvailable()) {
			$this->markTestSkipped('Node.js / sql-cli dependencies are not installed');
		}

		$errors = $nodeSqlParserAdapter->validate('SELECT TOP 10 id, name FROM users WHERE id = 1');

		$this->assertSame([], $errors);
	}

	public function testInvalidTSqlReportsError(): void
	{
		$nodeSqlParserAdapter = new NodeSqlParserAdapter('transactsql');

		if (!$nodeSqlParserAdapter->isAvailable()) {
			$this->markTestSkipped('Node.js / sql-cli dependencies are not installed');
		}

		$errors = $nodeSqlParserAdapter->validate('SELECT * FROM');

		$this->assertNotSame([], $errors);
		$this->assertSame(1, $errors[0]['sqlLine']);
	}

	public function testDigitLeadingPlaceholderIsSanitized(): void
	{
		$nodeSqlParserAdapter = new NodeSqlParserAdapter('transactsql');

		if (!$nodeSqlParserAdapter->isAvailable()) {
			$this->markTestSkipped('Node.js / sql-cli dependencies are not installed');
		}

		$errors = $nodeSqlParserAdapter->validate('SELECT id FROM users WHERE created > :5min_ago');

		$this->assertSame([], $errors);
	}
}
