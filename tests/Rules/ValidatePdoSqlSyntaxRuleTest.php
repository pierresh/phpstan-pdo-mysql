<?php declare(strict_types=1);

namespace Pierresh\PhpStanPdoMysql\Tests\Rules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Pierresh\PhpStanPdoMysql\Rules\ValidatePdoSqlSyntaxRule;

/**
 * @extends RuleTestCase<ValidatePdoSqlSyntaxRule>
 */
class ValidatePdoSqlSyntaxRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new ValidatePdoSqlSyntaxRule();
    }

    public function testRule(): void
    {
        $this->analyse([__DIR__ . '/../Fixtures/SqlSyntaxErrors.php'], [
            [
                'SQL syntax error in query(): An expression was expected.',
                19,
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
