<?php

declare(strict_types=1);

if (!function_exists('ddt')) {
	/**
	 * Dump Debug Type - Inspect a value and output its PHPStan type definition
	 *
	 * This helper function inspects PHP objects/arrays at runtime and generates
	 * a PHPStan type annotation that can be copy-pasted into your code.
	 *
	 * Usage in PHPUnit tests:
	 * ```php
	 * $row = $stmt->fetch();
	 * ddt($row);
	 * ```
	 *
	 * Output:
	 * ```
	 * /**
	 *  * @phpstan-type Item object{
	 *  *  id: int,
	 *  *  name: string,
	 *  *  status: int,
	 *  * }
	 *  *\/
	 * ```
	 *
	 * @param mixed $value The value to inspect
	 * @param bool $returnOnly For testing only - return string instead of exiting
	 */
	function ddt(mixed $value, bool $returnOnly = false): string
	{
		$typeDefinition = detectType($value, 0);

		// Format multi-line type definitions with proper PHPDoc leading asterisks
		$lines = explode("\n", $typeDefinition);
		$formattedType = $lines[0];
		for ($i = 1; $i < count($lines); $i++) {
			$formattedType .= "\n * " . $lines[$i];
		}

		$output = <<<PHPSTAN
		/**
		 * @phpstan-type Item {$formattedType}
		 */

		PHPSTAN;

		if ($returnOnly) {
			return $output;
		}

		echo $output . "\n";
		exit(0);
	}
}

if (!function_exists('detectType')) {
	/**
	 * Recursively detect the PHPStan type of a value
	 *
	 * @param mixed $value The value to inspect
	 * @param int $depth Current recursion depth
	 * @param array<int, int> $visitedObjects Track visited object IDs to prevent circular references
	 * @return string PHPStan type syntax
	 */
	function detectType(
		mixed $value,
		int $depth = 0,
		array &$visitedObjects = [],
	): string {
		// Prevent infinite recursion
		if ($depth > 5) {
			return 'mixed';
		}

		return match (true) {
			$value === null => 'null',
			is_int($value) => 'int',
			is_float($value) => 'float',
			is_string($value) => 'string',
			is_bool($value) => 'bool',
			is_array($value) => detectArrayType($value, $depth, $visitedObjects),
			is_object($value) => detectObjectType($value, $depth, $visitedObjects),
			default => 'mixed',
		};
	}
}

if (!function_exists('detectArrayType')) {
	/**
	 * Detect PHPStan type for arrays
	 *
	 * @param array<mixed> $array
	 * @param array<int, int> $visitedObjects
	 */
	function detectArrayType(
		array $array,
		int $depth,
		array &$visitedObjects,
	): string {
		if ($array === []) {
			return 'array{}';
		}

		// Check if it's an associative array (has string keys)
		$isAssociative = false;
		foreach (array_keys($array) as $key) {
			if (is_string($key)) {
				$isAssociative = true;
				break;
			}
		}

		if ($isAssociative) {
			// Format as array shape
			return formatArrayShape($array, $depth, $visitedObjects);
		}

		// Sequential array - detect common element type
		return formatSequentialArray($array, $depth, $visitedObjects);
	}
}

if (!function_exists('formatArrayShape')) {
	/**
	 * Format associative array as PHPStan array shape
	 *
	 * @param array<mixed> $array
	 * @param array<int, int> $visitedObjects
	 */
	function formatArrayShape(
		array $array,
		int $depth,
		array &$visitedObjects,
	): string {
		$properties = [];
		$indent = str_repeat('  ', $depth) . ' ';

		// Sample up to 50 items
		$count = 0;
		foreach ($array as $key => $value) {
			if ($count >= 50) {
				break;
			}

			$type = detectType($value, $depth + 1, $visitedObjects);
			$properties[] = sprintf('%s%s: %s,', $indent, $key, $type);
			$count++;
		}

		$baseIndent = str_repeat(' ', $depth);
		return (
			"array{\n"
			. implode("\n", $properties)
			. sprintf('%s%s}', PHP_EOL, $baseIndent)
		);
	}
}

if (!function_exists('formatSequentialArray')) {
	/**
	 * Format sequential array as PHPStan array<int, type>
	 *
	 * @param array<mixed> $array
	 * @param array<int, int> $visitedObjects
	 */
	function formatSequentialArray(
		array $array,
		int $depth,
		array &$visitedObjects,
	): string {
		// Sample first 50 items to detect type
		$sample = array_slice($array, 0, 50);
		$types = [];

		foreach ($sample as $value) {
			$type = detectType($value, $depth + 1, $visitedObjects);
			$types[$type] = true;
		}

		// If all items have the same type, use that
		if (count($types) === 1) {
			$type = array_key_first($types);
			return sprintf('array<int, %s>', $type);
		}

		// Mixed types
		return 'array<int, mixed>';
	}
}

if (!function_exists('detectObjectType')) {
	/**
	 * Detect PHPStan type for objects
	 *
	 * @param array<int, int> $visitedObjects
	 */
	function detectObjectType(
		object $object,
		int $depth,
		array &$visitedObjects,
	): string {
		// Check for circular references
		$objectId = spl_object_id($object);
		if (isset($visitedObjects[$objectId])) {
			return 'mixed'; // Circular reference detected
		}

		$visitedObjects[$objectId] = 1;

		// Get public properties using get_object_vars()
		// This works for both stdClass and class instances
		$properties = get_object_vars($object);

		if ($properties === []) {
			unset($visitedObjects[$objectId]);
			return 'object{}';
		}

		$formattedProperties = [];
		$indent = str_repeat('  ', $depth) . ' ';

		foreach ($properties as $name => $value) {
			$type = detectType($value, $depth + 1, $visitedObjects);
			$formattedProperties[] = sprintf('%s%s: %s,', $indent, $name, $type);
		}

		unset($visitedObjects[$objectId]);

		$baseIndent = str_repeat(' ', $depth);
		return (
			"object{\n"
			. implode("\n", $formattedProperties)
			. sprintf('%s%s}', PHP_EOL, $baseIndent)
		);
	}
}
