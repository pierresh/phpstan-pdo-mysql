<?php declare(strict_types=1);

namespace Pierresh\PhpStanPdoMysql\Tests\Rules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Pierresh\PhpStanPdoMysql\Rules\ValidatePdoParameterBindingsRule;

/**
 * @extends RuleTestCase<ValidatePdoParameterBindingsRule>
 */
class ValidatePdoParameterBindingsRuleTest extends RuleTestCase
{
	protected function getRule(): Rule
	{
		return new ValidatePdoParameterBindingsRule();
	}

	public function testRule(): void
	{
		$this->analyse([__DIR__ . '/../Fixtures/ParameterBindingErrors.php'], [
			[
				'Missing parameter :name in execute() array - SQL query (line 19) expects this parameter',
				22,
			],
			[
				'Parameter :extra in execute() array is not used in SQL query (line 28)',
				29,
			],
			[
				'Missing parameter :id in execute() array - SQL query (line 35) expects this parameter',
				37,
			],
			[
				'Parameter :name in execute() array is not used in SQL query (line 35)',
				37,
			],
			[
				'Missing parameter :user_id in execute() array - SQL query (line 61) expects this parameter',
				62,
			],
			['Parameter :id in execute() array is not used in SQL query (line 61)', 62],
			[
				'Missing parameter :name in execute() array - SQL query (line 77) expects this parameter',
				80,
			],
			[
				'Parameter :extra in execute() array is not used in SQL query (line 86)',
				87,
			],
			[
				'Missing parameter :user_id in execute() array - SQL query (line 103) expects this parameter',
				104,
			],
			[
				'Parameter :id in execute() array is not used in SQL query (line 103)',
				104,
			],
			[
				'Parameter :id in execute() array is not used in SQL query (line 110)',
				111,
			],
			[
				'Parameter :name in execute() array is not used in SQL query (line 110)',
				111,
			],
			[
				'Missing binding for :user_id - SQL query (line 117) expects this parameter but no bindValue/bindParam found before execute()',
				119,
			],
			[
				'Parameter :user_i is bound but not used in SQL query (line 117)',
				119,
			],
			[
				'Missing binding for :issue_code - SQL query (line 134) expects this parameter but no bindValue/bindParam found before execute()',
				148,
			],
			[
				'Parameter :issu_code is bound but not used in SQL query (line 134)',
				154,
			],
			[
				'Missing binding for :issue_code - SQL query (line 134) expects this parameter but no bindValue/bindParam found before execute()',
				157,
			],
			[
				'Parameter :issu_code is bound but not used in SQL query (line 134)',
				154,
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
