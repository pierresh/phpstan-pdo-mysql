<?php declare(strict_types=1);

namespace Pierresh\PhpStanPdoMysql\Tests\Rules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Pierresh\PhpStanPdoMysql\Rules\DetectSelfReferenceConditionsRule;

/**
 * @extends RuleTestCase<DetectSelfReferenceConditionsRule>
 */
class DetectSelfReferenceConditionsRuleTest extends RuleTestCase
{
	protected function getRule(): Rule
	{
		return new DetectSelfReferenceConditionsRule();
	}

	public function testRule(): void
	{
		$this->analyse([__DIR__ . '/../Fixtures/SelfReferenceErrors.php'], [
			[
				"Self-referencing JOIN condition: 'sp_list.sp_id = sp_list.sp_id'",
				22,
			],
			[
				"Self-referencing WHERE condition: 'users.id = users.id'",
				34,
			],
			[
				"Self-referencing WHERE condition: 'users.id = users.id'",
				45,
			],
			[
				"Self-referencing JOIN condition: 'orders.id = orders.id'",
				56,
			],
			[
				"Self-referencing WHERE condition: 'users.id = users.id'",
				57,
			],
			[
				"Self-referencing JOIN condition: 'orders.user_id = orders.user_id'",
				68,
			],
			[
				"Self-referencing WHERE condition: 'products.category_id = products.category_id'",
				107,
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
