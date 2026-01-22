<?php declare(strict_types=1);

namespace Pierresh\PhpStanPdoMysql\Tests\Rules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Pierresh\PhpStanPdoMysql\Rules\DetectTautologicalConditionsRule;

/**
 * @extends RuleTestCase<DetectTautologicalConditionsRule>
 */
class DetectTautologicalConditionsRuleTest extends RuleTestCase
{
	protected function getRule(): Rule
	{
		return new DetectTautologicalConditionsRule();
	}

	public function testRule(): void
	{
		$this->analyse([__DIR__ . '/../Fixtures/TautologicalConditions.php'], [
			// Always-true conditions
			[
				"Tautological condition in WHERE clause: '1 = 1' (always true)",
				26,
			],
			[
				"Tautological condition in WHERE clause: '0 = 0' (always true)",
				37,
			],
			[
				"Tautological condition in WHERE clause: '42 = 42' (always true)",
				48,
			],
			[
				"Tautological condition in WHERE clause: ''yes' = 'yes'' (always true)",
				59,
			],
			[
				"Tautological condition in WHERE clause: 'TRUE = TRUE' (always true)",
				70,
			],
			[
				"Tautological condition in WHERE clause: 'FALSE = FALSE' (always true)",
				81,
			],
			[
				"Tautological condition in JOIN clause: '1 = 1' (always true)",
				92,
			],
			[
				"Tautological condition in HAVING clause: '1 = 1' (always true)",
				104,
			],
			[
				"Tautological condition in WHERE clause: '1 = 1' (always true)",
				115,
			],
			[
				"Tautological condition in WHERE clause: '1 = 1' (always true)",
				131,
			],
			// Always-false conditions
			[
				"Tautological condition in WHERE clause: '1 = 0' (always false)",
				142,
			],
			[
				"Tautological condition in WHERE clause: ''a' = 'b'' (always false)",
				153,
			],
			[
				"Tautological condition in WHERE clause: 'TRUE = FALSE' (always false)",
				164,
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
