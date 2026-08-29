<?php declare(strict_types=1);

namespace Pierresh\PhpStanPdoMysql\Tests\Rules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Pierresh\PhpStanPdoMysql\Rules\ValidatePdoSqlSyntaxRule;
use Pierresh\PhpStanPdoMysql\SqlLinter\NodeSqlParserAdapter;

/**
 * @extends RuleTestCase<ValidatePdoSqlSyntaxRule>
 */
class ValidatePdoPostgresSyntaxRuleTest extends RuleTestCase
{
	protected function getRule(): Rule
	{
		return new ValidatePdoSqlSyntaxRule(new NodeSqlParserAdapter('postgresql'));
	}

	public function testRule(): void
	{
		// Raw error messages from node-sql-parser (via sql-cli/dist/sql-lint.js), reproduced
		// literally with nowdoc to avoid escaping mistakes on the embedded quotes/backslashes.
		$incompleteQueryError = <<<'EOT'
SQL syntax error in query(): Expected "$", "$$", "'", "(", "--", "/*", "@", "@@", "CURRENT_DATE", "CURRENT_TIME", "CURRENT_TIMESTAMP", "CURRENT_USER", "DUAL", "EXTRACT", "LATERAL", "NTILE", "POSITION", "SESSION_USER", "SYSTEM_USER", "USER", "VALUES", "\"", "`", "crosstab", "json_to_record", "json_to_recordset", "jsonb_to_record", "jsonb_to_recordset", "make_interval", "now", "substring", "trim", [ \t\n\r], or [A-Za-z_一-龥À-ſ] but end of input found.
EOT;

		$unterminatedQuotedIdentifierError = <<<'EOT'
SQL syntax error in prepare(): Expected "''", "\"", "\\", "\\'", "\\/", "\\\"", "\\\\", "\\b", "\\f", "\\n", "\\r", "\\t", "\\u", [^"\\\0-\x1F\x7F], or [^"] but end of input found.
EOT;

		$this->analyse([__DIR__ . '/../Fixtures/PostgresSyntaxErrors.php'], [
			[$incompleteQueryError, 19],
			[$unterminatedQuotedIdentifierError, 32],
		]);
	}

	public static function getAdditionalConfigFiles(): array
	{
		return [
			__DIR__ . '/../../extension.neon',
		];
	}
}
