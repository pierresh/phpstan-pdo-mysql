<?php declare(strict_types=1);

namespace Pierresh\PhpStanPdoMysql\Tests\Rules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Pierresh\PhpStanPdoMysql\Rules\DetectInvalidTableReferencesRule;

/**
 * @extends RuleTestCase<DetectInvalidTableReferencesRule>
 */
class DetectInvalidTableReferencesRuleTest extends RuleTestCase
{
	protected function getRule(): Rule
	{
		return new DetectInvalidTableReferencesRule();
	}

	public function testRule(): void
	{
		$this->analyse([__DIR__ . '/../Fixtures/InvalidTableReferences.php'], [
			[
				"Invalid table reference 'user' - available tables/aliases: users",
				22, // Line with "user.name"
			],
			[
				"Invalid table reference 'usr' - available tables/aliases: u, users",
				33, // Line with "usr.name"
			],
			[
				"Invalid table reference 'orders' - available tables/aliases: users",
				44, // Line with "orders.order_id"
			],
			[
				"Invalid table reference 'usr' - available tables/aliases: u, users, o, orders",
				57, // Line with "usr.id" in JOIN
			],
			[
				"Invalid table reference 'user' - available tables/aliases: u, users, o, orders",
				67, // Line with "user.id"
			],
			[
				"Invalid table reference 'ord' - available tables/aliases: u, users, o, orders",
				67, // Line with "ord.total" (same line as above)
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
