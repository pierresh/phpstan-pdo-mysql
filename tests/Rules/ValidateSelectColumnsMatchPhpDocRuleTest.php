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
                'SELECT column mismatch: PHPDoc expects property "name" but SELECT (line 48) has "nam" - possible typo?',
                51,
            ],
            [
                'SELECT column missing: PHPDoc expects property "email" but it is not in the SELECT query (line 48)',
                51,
            ],
            [
                'SELECT column mismatch: PHPDoc expects property "name" but SELECT (line 76) has "nam" - possible typo?',
                79,
            ],
            [
                'SELECT column missing: PHPDoc expects property "email" but it is not in the SELECT query (line 86)',
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
