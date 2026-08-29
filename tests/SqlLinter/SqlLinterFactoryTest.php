<?php declare(strict_types=1);

namespace Pierresh\PhpStanPdoMysql\Tests\SqlLinter;

use PHPUnit\Framework\TestCase;
use Pierresh\PhpStanPdoMysql\SqlLinter\NodeSqlParserAdapter;
use Pierresh\PhpStanPdoMysql\SqlLinter\SqlFtwAdapter;
use Pierresh\PhpStanPdoMysql\SqlLinter\SqlLinterFactory;

class SqlLinterFactoryTest extends TestCase
{
	public function testDefaultsToMysql(): void
	{
		$this->assertInstanceOf(SqlFtwAdapter::class, SqlLinterFactory::create('mysql'));
	}

	public function testUnknownDialectFallsBackToMysql(): void
	{
		$this->assertInstanceOf(SqlFtwAdapter::class, SqlLinterFactory::create('unknown'));
	}

	public function testMssqlUsesNodeSqlParserAdapter(): void
	{
		$this->assertInstanceOf(NodeSqlParserAdapter::class, SqlLinterFactory::create('mssql'));
	}

	public function testPostgresqlUsesNodeSqlParserAdapter(): void
	{
		$this->assertInstanceOf(NodeSqlParserAdapter::class, SqlLinterFactory::create('postgresql'));
	}
}
