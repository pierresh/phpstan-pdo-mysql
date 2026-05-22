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
				'Missing |false in @var type: fetch() can return false when no results found. Either add |false to the type or check for false/rowCount() before using the result (line 24)',
				27,
			],
			[
				'SELECT column mismatch: PHPDoc expects property "name" but SELECT (line 24) has "nam" - possible typo?',
				27,
			],
			[
				'Missing |false in @var type: fetch() can return false when no results found. Either add |false to the type or check for false/rowCount() before using the result (line 33)',
				36,
			],
			[
				'SELECT column missing: PHPDoc expects property "email" but it is not in the SELECT query (line 33)',
				36,
			],
			[
				'Missing |false in @var type: fetch() can return false when no results found. Either add |false to the type or check for false/rowCount() before using the result (line 42)',
				47,
			],
			[
				'Missing |false in @var type: fetch() can return false when no results found. Either add |false to the type or check for false/rowCount() before using the result (line 53)',
				56,
			],
			[
				'SELECT column mismatch: PHPDoc expects property "name" but SELECT (line 53) has "nam" - possible typo?',
				56,
			],
			[
				'SELECT column missing: PHPDoc expects property "email" but it is not in the SELECT query (line 53)',
				56,
			],
			[
				'Missing |false in @var type: fetch() can return false when no results found. Either add |false to the type or check for false/rowCount() before using the result (line 62)',
				67,
			],
			[
				'Missing |false in @var type: fetch() can return false when no results found. Either add |false to the type or check for false/rowCount() before using the result (line 73)',
				78,
			],
			[
				'Missing |false in @var type: fetch() can return false when no results found. Either add |false to the type or check for false/rowCount() before using the result (line 85)',
				88,
			],
			[
				'SELECT column mismatch: PHPDoc expects property "name" but SELECT (line 85) has "nam" - possible typo?',
				88,
			],
			[
				'Missing |false in @var type: fetch() can return false when no results found. Either add |false to the type or check for false/rowCount() before using the result (line 95)',
				105,
			],
			[
				'SELECT column missing: PHPDoc expects property "email" but it is not in the SELECT query (line 95)',
				105,
			],
			[
				'Missing |false in @var type: fetch() can return false when no results found. Either add |false to the type or check for false/rowCount() before using the result (line 115)',
				122,
			],
			[
				'Missing |false in @var type: fetch() can return false when no results found. Either add |false to the type or check for false/rowCount() before using the result (line 130)',
				133,
			],
			[
				'Missing |false in @var type: fetch() can return false when no results found. Either add |false to the type or check for false/rowCount() before using the result (line 141)',
				146,
			],
			[
				'Type mismatch: fetchAll() returns array<object{...}> but PHPDoc specifies object{...} (line 154)',
				157,
			],
			[
				'Type mismatch: fetch() returns object{...} but PHPDoc specifies array<object{...}> (line 175)',
				178,
			],
			[
				'Type mismatch: fetchAll() returns array<object{...}> but PHPDoc specifies object{...} (line 187)',
				190,
			],
			[
				'Type mismatch: fetch() returns object{...} but PHPDoc specifies array<object{...}> (line 208)',
				211,
			],
			[
				'Missing |false in @var type: fetch() can return false when no results found. Either add |false to the type or check for false/rowCount() before using the result (line 220)',
				223,
			],
			[
				'Missing |false in @var type: fetchObject() can return false when no results found. Either add |false to the type or check for false/rowCount() before using the result (line 290)',
				293,
			],
			[
				'Missing |false in @var type: fetch() can return false when no results found. Either add |false to the type or check for false/rowCount() before using the result (line 340)',
				347,
			],
			[
				'SELECT column mismatch: PHPDoc expects property "name" but SELECT (line 372) has "nam" - possible typo?',
				376,
			],
			[
				'Missing |false in @var type: fetch() can return false when no results found. Either add |false to the type or check for false/rowCount() before using the result (line 409)',
				414,
			],
			[
				'Missing |false in @var type: fetchObject() can return false when no results found. Either add |false to the type or check for false/rowCount() before using the result (line 433)',
				436,
			],
			[
				'Missing |false in @var type: fetch() can return false when no results found. Either add |false to the type or check for false/rowCount() before using the result (line 453)',
				456,
			],
			[
				'SELECT column mismatch: PHPDoc expects property "name" but SELECT (line 453) has "nam" - possible typo?',
				456,
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
