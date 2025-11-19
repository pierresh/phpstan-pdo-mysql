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
			['Missing parameter :name in execute()',        22],
			['Parameter :extra in execute() is not used',   29],
			['Missing parameter :id in execute()',          37],
			['Parameter :name in execute() is not used',    37],
			['Missing parameter :user_id in execute()',     62],
			['Parameter :id in execute() is not used',      62],
			['Missing parameter :name in execute()',        80],
			['Parameter :extra in execute() is not used',   87],
			['Missing parameter :user_id in execute()',     104],
			['Parameter :id in execute() is not used',      104],
			['Parameter :id in execute() is not used',      111],
			['Parameter :name in execute() is not used',    111],
			['Missing bindValue/bindParam for :user_id',    119],
			['Parameter :user_i is bound but not used',     119],
			['Parameter :extra in execute() is not used',   139], // Multi-line array: error on parameter line
			['Missing bindValue/bindParam for :issue_code', 169],
			['Parameter :issu_code is bound but not used',  175],
			['Missing bindValue/bindParam for :issue_code', 178],
			['Parameter :issu_code is bound but not used',  175],
		]);
	}

	public static function getAdditionalConfigFiles(): array
	{
		return [
			__DIR__ . '/../../extension.neon',
		];
	}
}
