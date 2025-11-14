<?php declare(strict_types=1);

namespace Pierresh\PhpStanPdoMysql\Tests\Rules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Pierresh\PhpStanPdoMysql\Rules\ValidatePdoSqlSyntaxRule;
use Pierresh\PhpStanPdoMysql\SqlLinter\SqlFtwAdapter;

/**
 * @extends RuleTestCase<ValidatePdoSqlSyntaxRule>
 */
class ValidatePdoSqlSyntaxRuleTest extends RuleTestCase
{
	protected function getRule(): Rule
	{
		return new ValidatePdoSqlSyntaxRule(new SqlFtwAdapter());
	}

	public function testRule(): void
	{
		$this->analyse([__DIR__ . '/../Fixtures/SqlSyntaxErrors.php'], [
			[
				'SQL syntax error in query(): Expected token NAME ~RESERVED, but end of query found instead.',
				19,
			],
			[
				'SQL syntax error in prepare(): Expected token NAME|VALUE, but token SYMBOL with value ")" (1) found instead.',
				34, // Error on line 2 of the multi-line SQL (line 33 + 1)
			],
			[
				'SQL syntax error in prepare(): Expected token NAME ~RESERVED, but token NAME|UNQUOTED_NAME|KEYWORD|RESERVED with value "FROM" (4) found instead.',
				42, // Error on line 2 of the multi-line SQL (line 41 + 1)
			],
			[
				'SQL syntax error in prepare(): Expected token NAME|VALUE, but end of query found instead.',
				51, // Error on line 3 of the multi-line SQL (line 49 + 2)
			],
		]);
	}

	public static function getAdditionalConfigFiles(): array
	{
		return [
			__DIR__ . '/../../extension.neon',
		];
	}
}
