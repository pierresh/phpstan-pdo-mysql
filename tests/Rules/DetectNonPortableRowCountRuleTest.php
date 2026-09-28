<?php declare(strict_types=1);

namespace Pierresh\PhpStanPdoMysql\Tests\Rules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Pierresh\PhpStanPdoMysql\Rules\DetectNonPortableRowCountRule;

/**
 * @extends RuleTestCase<DetectNonPortableRowCountRule>
 */
class DetectNonPortableRowCountRuleTest extends RuleTestCase
{
	protected function getRule(): Rule
	{
		return new DetectNonPortableRowCountRule();
	}

	public function testRule(): void
	{
		$this->analyse([__DIR__ . '/../Fixtures/NonPortableRowCount.php'], [
			[
				'Not portable to SQL Server: $stmt->rowCount() > 0 on a SELECT (line 22). On SQL Server, rowCount() after a SELECT returns -1 when there are rows. Use fetch() and check the result against false instead.',
				25,
			],
			[
				'Not portable to SQL Server: $this->users->rowCount() === 1 on a SELECT (line 16). On SQL Server, rowCount() after a SELECT returns -1 when there are rows. Use fetch() and check the result against false instead.',
				32,
			],
			[
				'Not portable to SQL Server: $stmt->rowCount() < 1 on a SELECT (line 38). On SQL Server, rowCount() after a SELECT returns -1 when there are rows. Use fetch() and check the result against false instead.',
				41,
			],
			[
				'Not portable to SQL Server: 0 < $stmt->rowCount() on a SELECT (line 48). On SQL Server, rowCount() after a SELECT returns -1 when there are rows. Use fetch() and check the result against false instead.',
				50,
			],
			[
				'Not portable to SQL Server: $stmt->rowCount() >= 1 on a SELECT (line 55). On SQL Server, rowCount() after a SELECT returns -1 when there are rows. Use fetch() and check the result against false instead.',
				61,
			],
		]);
	}
}
