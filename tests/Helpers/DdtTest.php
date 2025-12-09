<?php

declare(strict_types=1);

namespace Pierresh\PhpStanPdoMysql\Tests\Helpers;

use PHPUnit\Framework\TestCase;
use stdClass;

class DdtTest extends TestCase
{
	public function testSimpleObjectWithScalarProperties(): void
	{
		$obj = new stdClass();
		$obj->id = 123;
		$obj->name = 'example';
		$obj->status = 1;

		$output = ddt($obj, returnOnly: true);

		$expected = <<<'PHPSTAN'
		/**
		 * @phpstan-type Item object{
		 *  id: int,
		 *  name: string,
		 *  status: int,
		 * }
		 */

		PHPSTAN;

		$this->assertSame($expected, $output);
	}

	public function testNestedObjects(): void
	{
		$metadata = new stdClass();
		$metadata->created_at = '2024-01-01';
		$metadata->updated_at = '2024-01-02';

		$workflow = new stdClass();
		$workflow->id = 1;
		$workflow->status = 'active';
		$workflow->metadata = $metadata;

		$output = ddt($workflow, returnOnly: true);

		$expected = <<<'PHPSTAN'
		/**
		 * @phpstan-type Item object{
		 *  id: int,
		 *  status: string,
		 *  metadata: object{
		 *    created_at: string,
		 *    updated_at: string,
		 *  },
		 * }
		 */

		PHPSTAN;

		$this->assertSame($expected, $output);
	}

	public function testAssociativeArray(): void
	{
		$config = [
			'database' => 'mysql',
			'port' => 3306,
			'debug' => true,
		];

		$output = ddt($config, returnOnly: true);

		$expected = <<<'PHPSTAN'
		/**
		 * @phpstan-type Item array{
		 *  database: string,
		 *  port: int,
		 *  debug: bool,
		 * }
		 */

		PHPSTAN;

		$this->assertSame($expected, $output);
	}

	public function testSequentialArrayWithSameType(): void
	{
		$ids = [1, 2, 3, 4, 5];

		$output = ddt($ids, returnOnly: true);

		$expected = <<<'PHPSTAN'
		/**
		 * @phpstan-type Item array<int, int>
		 */

		PHPSTAN;

		$this->assertSame($expected, $output);
	}

	public function testSequentialArrayWithMixedTypes(): void
	{
		$values = [1, 'hello', true, 3.14];

		$output = ddt($values, returnOnly: true);

		$expected = <<<'PHPSTAN'
		/**
		 * @phpstan-type Item array<int, mixed>
		 */

		PHPSTAN;

		$this->assertSame($expected, $output);
	}

	public function testEmptyObject(): void
	{
		$obj = new stdClass();

		$output = ddt($obj, returnOnly: true);

		$expected = <<<'PHPSTAN'
		/**
		 * @phpstan-type Item object{}
		 */

		PHPSTAN;

		$this->assertSame($expected, $output);
	}

	public function testEmptyArray(): void
	{
		$arr = [];

		$output = ddt($arr, returnOnly: true);

		$expected = <<<'PHPSTAN'
		/**
		 * @phpstan-type Item array{}
		 */

		PHPSTAN;

		$this->assertSame($expected, $output);
	}

	public function testNullValue(): void
	{
		$value = null;

		$output = ddt($value, returnOnly: true);

		$expected = <<<'PHPSTAN'
		/**
		 * @phpstan-type Item null
		 */

		PHPSTAN;

		$this->assertSame($expected, $output);
	}

	public function testClassInstanceTreatedAsObjectShape(): void
	{
		$instance = new class {
			public int $id = 123;

			public string $name = 'Test';

			// Should not appear
		};

		$output = ddt($instance, returnOnly: true);

		$expected = <<<'PHPSTAN'
		/**
		 * @phpstan-type Item object{
		 *  id: int,
		 *  name: string,
		 * }
		 */

		PHPSTAN;

		$this->assertSame($expected, $output);
	}

	public function testAllScalarTypes(): void
	{
		$obj = new stdClass();
		$obj->int_val = 42;
		$obj->float_val = 3.14;
		$obj->string_val = 'hello';
		$obj->bool_val = true;
		$obj->null_val = null;

		$output = ddt($obj, returnOnly: true);

		$expected = <<<'PHPSTAN'
		/**
		 * @phpstan-type Item object{
		 *  int_val: int,
		 *  float_val: float,
		 *  string_val: string,
		 *  bool_val: bool,
		 *  null_val: null,
		 * }
		 */

		PHPSTAN;

		$this->assertSame($expected, $output);
	}

	public function testDeepNesting(): void
	{
		// Create a deeply nested structure
		$level4 = new stdClass();
		$level4->value = 'deep';

		$level3 = new stdClass();
		$level3->level4 = $level4;

		$level2 = new stdClass();
		$level2->level3 = $level3;

		$level1 = new stdClass();
		$level1->level2 = $level2;

		$root = new stdClass();
		$root->level1 = $level1;

		$output = ddt($root, returnOnly: true);

		// Should handle nesting up to 5 levels
		$this->assertStringContainsString('level1', $output);
		$this->assertStringContainsString('level2', $output);
		$this->assertStringContainsString('level3', $output);
		$this->assertStringContainsString('level4', $output);
		$this->assertStringContainsString('value', $output);
	}

	public function testCircularReferenceDetection(): void
	{
		$obj1 = new stdClass();
		$obj2 = new stdClass();

		$obj1->name = 'first';
		$obj1->ref = $obj2;

		$obj2->name = 'second';
		$obj2->ref = $obj1; // Circular reference

		$output = ddt($obj1, returnOnly: true);

		// Should detect circular reference and output 'mixed'
		$this->assertStringContainsString('mixed', $output);
	}
}
