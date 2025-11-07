<?php declare(strict_types=1);

namespace Pierresh\PhpStanPdoMysql\Tests\Rules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Pierresh\PhpStanPdoMysql\Rules\ValidatePdoParameterBindingsRule;

/**
 * @extends RuleTestCase<ValidatePdoParameterBindingsRule>
 */
class ValidatePdoParameterBindingsRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new ValidatePdoParameterBindingsRule();
    }

    public function testRule(): void
    {
        $this->analyse([__DIR__ . '/../Fixtures/ParameterBindingErrors.php'], [
            [
                'Missing parameter :name in execute() array - SQL query (line 19) expects this parameter',
                20,
            ],
            [
                'Parameter :extra in execute() array is not used in SQL query (line 26)',
                27,
            ],
            [
                'Missing parameter :id in execute() array - SQL query (line 33) expects this parameter',
                35,
            ],
            [
                'Parameter :name in execute() array is not used in SQL query (line 33)',
                35,
            ],
            [
                'Missing parameter :user_id in execute() array - SQL query (line 57) expects this parameter',
                58,
            ],
            [
                'Parameter :id in execute() array is not used in SQL query (line 57)',
                58,
            ],
            [
                'Missing parameter :name in execute() array - SQL query (line 71) expects this parameter',
                72,
            ],
            [
                'Parameter :extra in execute() array is not used in SQL query (line 78)',
                79,
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
