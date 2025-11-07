# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a PHPStan extension that provides static analysis rules for PDO/MySQL code validation.
The primary language is PHP 8.1+
This project validates PDO code without requiring a database connection, catching errors at analysis time instead of runtime.

## Core Philosophy

This extension performs **static analysis only** - no database connection required. All validation is done by:
1. Parsing SQL strings using phpmyadmin/sql-parser
2. Analyzing AST nodes to track SQL queries through variables
3. Validating parameter bindings and SELECT columns against PHPDoc types

## Project Structure

```
src/Rules/                           # PHPStan rule implementations
  ValidatePdoSqlSyntaxRule.php      # Rule 1: Validates MySQL syntax
  ValidatePdoParameterBindingsRule.php  # Rule 2: Validates PDO parameter bindings
  ValidateSelectColumnsMatchPhpDocRule.php  # Rule 3: Validates SELECT columns vs PHPDoc

tests/                               # PHPUnit tests
  Rules/                             # Test cases for each rule
    ValidatePdoSqlSyntaxRuleTest.php
    ValidatePdoParameterBindingsRuleTest.php
    ValidateSelectColumnsMatchPhpDocRuleTest.php
  Fixtures/                          # Test fixture files with intentional errors
    SqlSyntaxErrors.php
    ParameterBindingErrors.php
    SelectColumnErrors.php

extension.neon                       # PHPStan configuration that registers the rules
composer.json                        # Package definition
```

## The Three Rules

### 1. ValidatePdoSqlSyntaxRule
- **Purpose**: Catches MySQL syntax errors in `prepare()` and `query()` calls
- **Examples**: Incomplete queries like `SELECT * FROM`, missing expressions
- **Key method**: `processNode()` - two-pass analysis to find SQL and validate syntax

### 2. ValidatePdoParameterBindingsRule
- **Purpose**: Ensures all SQL placeholders (`:name`) have corresponding `execute()` bindings
- **Key behavior**: When `execute()` receives an array, it ignores previous `bindValue()` calls
- **Validates**: Missing parameters, extra parameters, wrong parameter names

### 3. ValidateSelectColumnsMatchPhpDocRule
- **Purpose**: Validates SELECT columns match PHPDoc type annotations
- **Supports**: Direct annotations (`@var object{...}`) and type aliases (`@phpstan-type`)
- **Validates**: Missing columns, column name typos (case-sensitive)
- **Allows**: Extra columns in SELECT (selecting more than PHPDoc expects is fine)

## Common Patterns

### Two-Pass Analysis
All rules use this pattern:
1. **First pass**: Scan method for SQL strings (literals + variables)
2. **Second pass**: Find PDO method calls and validate against collected SQL

This allows rules to work with both:
```php
// Direct literals
$stmt = $db->prepare("SELECT ...");

// Variables
$sql = "SELECT ...";
$stmt = $db->prepare($sql);
```

### SQL Detection Heuristics
Rules look for SQL keywords to identify query strings:
- Fast string contains checks before expensive parsing
- Skips very long strings (>10,000 chars) for performance
- Gracefully handles missing dependencies

### Cross-Method Tracking
Rules can track SQL prepared in constructors and used in other methods by storing SQL strings at the class scope.

## Testing Approach

Tests use PHPStan's RuleTestCase pattern:
1. Create fixture files with intentional errors
2. Annotate expected errors with comments
3. Run PHPStan analysis and compare actual vs expected errors

Example test structure:
```php
public function testRule(): void
{
    $this->analyse([__DIR__ . '/../Fixtures/SqlSyntaxErrors.php'], [
        ['SQL syntax error...', 15],  // [error message, line number]
    ]);
}
```

## Running Tests

```bash
# Watch mode (auto-runs on file changes)
composer test

# Single run
./vendor/bin/phpunit

# Test specific rule
./vendor/bin/phpunit tests/Rules/ValidatePdoSqlSyntaxRuleTest.php
```

## Running Analysis

To test the extension on fixture files:

```bash
# Analyze a specific fixture file
vendor/bin/phpstan analyze tests/Fixtures/SqlSyntaxErrors.php -c extension.neon --level=9

# See full error details
vendor/bin/phpstan analyze tests/Fixtures/ParameterBindingErrors.php -c extension.neon --level=9 --error-format=table
```

## Development Guidelines

### When Adding Features
1. Start with the test fixture - add examples with intentional errors
2. Update the test expectations
3. Implement the rule logic
4. Run tests to verify

### When Fixing Bugs
1. Add a failing test case that reproduces the bug
2. Fix the rule implementation
3. Verify all tests pass
4. Update README.md examples if needed

### Performance Considerations
- Early bailout for non-SQL code (check for SQL keywords first)
- Skip analysis for very long queries (>10,000 chars)
- Cache parsed SQL when possible
- Use efficient string matching before expensive operations

### Error Messages
Follow this format for consistency:
- Clear indication of what's wrong
- Reference the line number of the SQL query
- Suggest the fix when possible
- Use "possible typo?" for case-sensitive column mismatches

## Common Gotchas

1. **Case sensitivity**: MySQL column names are validated case-sensitively
2. **SELECT * behavior**: Rules skip validation for `SELECT *` and `SELECT table.*` queries (cannot be validated statically)
3. **Execute array behavior**: Passing an array to `execute()` ignores previous `bindValue()` calls
4. **Extra columns allowed**: Having more columns in SELECT than in PHPDoc is valid
5. **Long queries**: Queries over 10,000 characters are skipped for performance

## Dependencies

- **phpstan/phpstan**: Static analysis framework
- **phpmyadmin/sql-parser**: MySQL query parser (no database needed)
- **phpunit/phpunit**: Testing framework
- **spatie/phpunit-watcher**: Auto-run tests on file changes

## Extension Registration

The extension is auto-registered via composer.json's `extra.phpstan.includes` when using phpstan/extension-installer.

Manual registration in user's `phpstan.neon`:
```neon
includes:
    - vendor/pierresh/phpstan-pdo-mysql/extension.neon
```

## Future Enhancement Ideas

- Support for prepared statements across multiple files/classes
- Validation of column types (INT, VARCHAR, etc.) against PHPDoc
- Support for other SQL dialects (PostgreSQL, SQLite)
- Detection of SQL injection vulnerabilities
- Support for query builders (Doctrine, Eloquent)
