<?php declare(strict_types=1);

namespace Pierresh\PhpStanPdoMysql\Tests\Rules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Pierresh\PhpStanPdoMysql\Rules\DetectMySqlSpecificSyntaxRule;

/**
 * @extends RuleTestCase<DetectMySqlSpecificSyntaxRule>
 */
class DetectMySqlSpecificSyntaxRuleTest extends RuleTestCase
{
	protected function getRule(): Rule
	{
		return new DetectMySqlSpecificSyntaxRule();
	}

	public function testRule(): void
	{
		$this->analyse([__DIR__ . '/../Fixtures/MySqlSpecificSyntaxErrors.php'], [
			['Use COALESCE() instead of IFNULL() for database portability', 19],
			['Use CASE WHEN instead of IF() for database portability', 28],
			['Use CASE WHEN instead of IF() for database portability', 37],
			['Use COALESCE() instead of IFNULL() for database portability', 63],
			['Use CASE WHEN instead of IF() for database portability', 63],
			['Use COALESCE() instead of IFNULL() for database portability', 72],
			[
				'Bind current datetime to a PHP variable instead of NOW() for database portability',
				78,
			],
			[
				'Bind current date to a PHP variable instead of CURDATE() for database portability',
				85,
			],
			[
				'Use LIMIT count OFFSET offset instead of LIMIT offset, count for database portability',
				94,
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
