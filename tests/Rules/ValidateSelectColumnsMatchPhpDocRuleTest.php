<?php declare(strict_types=1);

namespace Pierresh\PhpStanPdoMysql\Tests\Rules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Pierresh\PhpStanPdoMysql\Rules\ValidateSelectColumnsMatchPhpDocRule;

/**
 * @extends RuleTestCase<ValidateSelectColumnsMatchPhpDocRule>
 */
class ValidateSelectColumnsMatchPhpDocRuleTest extends RuleTestCase
{
	protected function getRule(): Rule
	{
		return new ValidateSelectColumnsMatchPhpDocRule();
	}

	public function testRule(): void
	{
		$this->analyse([__DIR__ . '/../Fixtures/SelectColumnErrors.php'], [
			[
				'SELECT column mismatch: PHPDoc expects property "name" but SELECT (line 21) has "nam" - possible typo?',
				24,
			],
			[
				'SELECT column missing: PHPDoc expects property "email" but it is not in the SELECT query (line 30)',
				33,
			],
			[
				'SELECT column mismatch: PHPDoc expects property "name" but SELECT (line 50) has "nam" - possible typo?',
				53,
			],
			[
				'SELECT column missing: PHPDoc expects property "email" but it is not in the SELECT query (line 50)',
				53,
			],
			[
				'SELECT column mismatch: PHPDoc expects property "name" but SELECT (line 82) has "nam" - possible typo?',
				85,
			],
			[
				'SELECT column missing: PHPDoc expects property "email" but it is not in the SELECT query (line 92)',
				102,
			],
			[
				'Type mismatch: fetchAll() returns array<object{...}> but PHPDoc specifies object{...} (line 151)',
				154,
			],
			[
				'Type mismatch: fetch() returns object{...} but PHPDoc specifies array<object{...}> (line 172)',
				175,
			],
			[
				'Type mismatch: fetchAll() returns array<object{...}> but PHPDoc specifies object{...} (line 184)',
				187,
			],
			[
				'Type mismatch: fetch() returns object{...} but PHPDoc specifies array<object{...}> (line 205)',
				208,
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
