<?php declare(strict_types=1);

namespace Pierresh\PhpStanPdoMysql\SqlLinter;

/**
 * Resolves the configured SQL dialect (parameters.pdoMysql.dialect in extension.neon)
 * to the adapter that validates it.
 */
final class SqlLinterFactory
{
	public static function create(string $dialect): SqlLinterInterface
	{
		return match ($dialect) {
			'mssql' => new NodeSqlParserAdapter('transactsql'),
			'postgresql' => new NodeSqlParserAdapter('postgresql'),
			default => new SqlFtwAdapter(),
		};
	}
}
