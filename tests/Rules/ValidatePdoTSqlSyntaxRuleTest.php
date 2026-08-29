<?php declare(strict_types=1);

namespace Pierresh\PhpStanPdoMysql\Tests\Rules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Pierresh\PhpStanPdoMysql\Rules\ValidatePdoSqlSyntaxRule;
use Pierresh\PhpStanPdoMysql\SqlLinter\NodeSqlParserAdapter;

/**
 * @extends RuleTestCase<ValidatePdoSqlSyntaxRule>
 */
class ValidatePdoTSqlSyntaxRuleTest extends RuleTestCase
{
	protected function getRule(): Rule
	{
		return new ValidatePdoSqlSyntaxRule(new NodeSqlParserAdapter('transactsql'));
	}

	public function testRule(): void
	{
		// Raw error messages from node-sql-parser (via sql-cli/bin/sql-lint.js), reproduced
		// literally with nowdoc to avoid escaping mistakes on the embedded quotes/backslashes.
		$incompleteQueryError = <<<'EOT'
SQL syntax error in query(): Expected "#", "##", "$", "'", "(", "--", "/*", "@", "@@", "CURRENT_DATE", "CURRENT_TIME", "CURRENT_TIMESTAMP", "CURRENT_USER", "DUAL", "SESSION_USER", "SYSTEM_USER", "USER", "VALUES", "[", "\"", "`", [ \t\n\r], or [A-Za-z_@#一-龥] but end of input found.
EOT;

		$unterminatedBracketError = <<<'EOT'
SQL syntax error in prepare(): Expected "]" or [^\]] but end of input found.
EOT;

		$this->analyse([__DIR__ . '/../Fixtures/TSqlSyntaxErrors.php'], [
			[$incompleteQueryError, 19],
			[$unterminatedBracketError, 32],
		]);
	}

	public static function getAdditionalConfigFiles(): array
	{
		return [
			__DIR__ . '/../../extension.neon',
		];
	}
}
