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
				"Self-referencing JOIN condition: 'products.id = products.id'",
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
			[
				"Self-referencing JOIN condition: 'p.id = products.id'",
				114,
			],
			[
				"Self-referencing WHERE condition: 'u.id = users.id'",
				125,
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
