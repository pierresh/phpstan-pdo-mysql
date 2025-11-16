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
				'Missing |false in @var type: fetch() can return false when no results found. Either add |false to the type or check for false/rowCount() before using the result (line 21)',
				24,
			],
			[
				'SELECT column mismatch: PHPDoc expects property "name" but SELECT (line 21) has "nam" - possible typo?',
				24,
			],
			[
				'Missing |false in @var type: fetch() can return false when no results found. Either add |false to the type or check for false/rowCount() before using the result (line 30)',
				33,
			],
			[
				'SELECT column missing: PHPDoc expects property "email" but it is not in the SELECT query (line 30)',
				33,
			],
			[
				'Missing |false in @var type: fetch() can return false when no results found. Either add |false to the type or check for false/rowCount() before using the result (line 39)',
				44,
			],
			[
				'Missing |false in @var type: fetch() can return false when no results found. Either add |false to the type or check for false/rowCount() before using the result (line 50)',
				53,
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
				'Missing |false in @var type: fetch() can return false when no results found. Either add |false to the type or check for false/rowCount() before using the result (line 59)',
				64,
			],
			[
				'Missing |false in @var type: fetch() can return false when no results found. Either add |false to the type or check for false/rowCount() before using the result (line 70)',
				75,
			],
			[
				'Missing |false in @var type: fetch() can return false when no results found. Either add |false to the type or check for false/rowCount() before using the result (line 82)',
				85,
			],
			[
				'SELECT column mismatch: PHPDoc expects property "name" but SELECT (line 82) has "nam" - possible typo?',
				85,
			],
			[
				'Missing |false in @var type: fetch() can return false when no results found. Either add |false to the type or check for false/rowCount() before using the result (line 92)',
				102,
			],
			[
				'SELECT column missing: PHPDoc expects property "email" but it is not in the SELECT query (line 92)',
				102,
			],
			[
				'Missing |false in @var type: fetch() can return false when no results found. Either add |false to the type or check for false/rowCount() before using the result (line 112)',
				119,
			],
			[
				'Missing |false in @var type: fetch() can return false when no results found. Either add |false to the type or check for false/rowCount() before using the result (line 127)',
				130,
			],
			[
				'Missing |false in @var type: fetch() can return false when no results found. Either add |false to the type or check for false/rowCount() before using the result (line 138)',
				143,
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
			[
				'Missing |false in @var type: fetch() can return false when no results found. Either add |false to the type or check for false/rowCount() before using the result (line 217)',
				220,
			],
			[
				'Missing |false in @var type: fetchObject() can return false when no results found. Either add |false to the type or check for false/rowCount() before using the result (line 287)',
				290,
			],
			[
				'Missing |false in @var type: fetch() can return false when no results found. Either add |false to the type or check for false/rowCount() before using the result (line 337)',
				344,
			],
			[
				'SELECT column mismatch: PHPDoc expects property "name" but SELECT (line 353) has "nam" - possible typo?',
				357,
			],
			[
				'Missing |false in @var type: fetch() can return false when no results found. Either add |false to the type or check for false/rowCount() before using the result (line 390)',
				395,
			],
			[
				'Missing |false in @var type: fetchObject() can return false when no results found. Either add |false to the type or check for false/rowCount() before using the result (line 414)',
				417,
			],
			[
				'Missing |false in @var type: fetch() can return false when no results found. Either add |false to the type or check for false/rowCount() before using the result (line 434)',
				437,
			],
			[
				'SELECT column mismatch: PHPDoc expects property "name" but SELECT (line 434) has "nam" - possible typo?',
				437,
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
