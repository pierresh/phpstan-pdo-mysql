# PHPStan PDO MySQL Rules

Static analysis rules for PHPStan that validate PDO/MySQL code for common errors that would otherwise only be caught at runtime.

## Features

This extension provides three powerful rules that work without requiring a database connection:

1. **SQL Syntax Validation** - Detects MySQL syntax errors in `prepare()` and `query()` calls
2. **Parameter Binding Validation** - Ensures PDO parameters match SQL placeholders
3. **SELECT Column Validation** - Verifies SELECT columns match PHPDoc type annotations

All validation is performed statically by analyzing your code, so no database setup is needed.

## Installation

```bash
composer require --dev pierresh/phpstan-pdo-mysql
```

The extension will be automatically registered if you use [phpstan/extension-installer](https://github.com/phpstan/extension-installer).

Manual registration in `phpstan.neon`:

```neon
includes:
    - vendor/pierresh/phpstan-pdo-mysql/extension.neon
```

## Examples

### 1. SQL Syntax Validation

Catches syntax errors in SQL queries:

```php
// ❌ Missing FROM keyword
$stmt = $db->prepare("SELECT id, name users WHERE id = :id");
// Error: SQL syntax error in prepare(): Unexpected beginning of statement. (near "users")

// ❌ Incomplete query
$stmt = $db->query("SELECT * FROM");
// Error: SQL syntax error in query(): Unexpected end of statement.

// ✅ Valid SQL
$stmt = $db->prepare("SELECT id, name FROM users WHERE id = :id");
```

Works with both direct strings and variables:

```php
$sql = "SELECT id, name users WHERE id = :id"; // Missing FROM keyword
$stmt = $db->prepare($sql);
// Error: SQL syntax error in prepare(): Unexpected beginning of statement. (near "users")
```

### 2. Parameter Binding Validation

Ensures all SQL placeholders have corresponding bindings:

```php
// ❌ Missing parameter
$stmt = $db->prepare("SELECT * FROM users WHERE id = :id AND name = :name");
$stmt->execute(['id' => 1]); // Missing :name
// Error: Missing PDO parameter(s) in execute(): name

// ❌ Extra parameter
$stmt = $db->prepare("SELECT * FROM users WHERE id = :id");
$stmt->execute(['id' => 1, 'extra' => 'unused']);
// Error: Extra PDO parameter(s) in execute() not in SQL: extra

// ❌ Wrong parameter name
$stmt = $db->prepare("SELECT * FROM users WHERE id = :user_id");
$stmt->execute(['id' => 1]); // Should be :user_id
// Error: Missing PDO parameter(s) in execute(): user_id
// Error: Extra PDO parameter(s) in execute() not in SQL: id

// ✅ Valid bindings
$stmt = $db->prepare("SELECT * FROM users WHERE id = :id AND name = :name");
$stmt->execute(['id' => 1, 'name' => 'John']);
```

Important: When `execute()` receives an array, it ignores previous `bindValue()` calls:

```php
$stmt = $db->prepare("SELECT * FROM users WHERE id = :id");
$stmt->bindValue(':id', 1); // This is ignored!
$stmt->execute(['name' => 'John']); // Wrong parameter
// Error: Missing PDO parameter(s) in execute(): id
// Error: Extra PDO parameter(s) in execute() not in SQL: name
```

### 3. SELECT Column Validation

Validates that SELECT columns match the PHPDoc type annotation:

```php
// ❌ Column typo
$stmt = $db->prepare("SELECT id, nam, email FROM users WHERE id = :id");
$stmt->execute(['id' => 1]);

/** @var object{id: int, name: string, email: string} */
$user = $stmt->fetch();
// Error: Column "name" in @var type not found in SELECT query. Did you mean "nam"?

// ❌ Missing column
$stmt = $db->prepare("SELECT id, name FROM users WHERE id = :id");
$stmt->execute(['id' => 1]);

/** @var object{id: int, name: string, email: string} */
$user = $stmt->fetch();
// Error: Column "email" in @var type not found in SELECT query

// ❌ Extra column
$stmt = $db->prepare("SELECT id, name, email, created_at FROM users WHERE id = :id");
$stmt->execute(['id' => 1]);

/** @var object{id: int, name: string, email: string} */
$user = $stmt->fetch();
// Error: Extra column in SELECT query not in @var type: created_at

// ✅ Valid columns
$stmt = $db->prepare("SELECT id, name, email FROM users WHERE id = :id");
$stmt->execute(['id' => 1]);

/** @var object{id: int, name: string, email: string} */
$user = $stmt->fetch();
```

Supports `@phpstan-type` aliases:

```php
/**
 * @phpstan-type User object{id: int, name: string, email: string}
 */
class UserRepository
{
    public function findUser(int $id): void
    {
        $stmt = $this->db->prepare("SELECT id, nam FROM users WHERE id = :id");
        $stmt->execute(['id' => $id]);

        /** @var User */
        $user = $stmt->fetch();
        // Error: Column "name" in @var type not found in SELECT query. Did you mean "nam"?
    }
}
```

## Requirements

- PHP 8.1+
- PHPStan 1.10+
- phpmyadmin/sql-parser 5.0+

## How It Works

All three rules use a two-pass analysis approach:

1. **First pass**: Scan the method for SQL query strings (both direct literals and variables)
2. **Second pass**: Find all `prepare()`/`query()` calls and validate them

This allows the rules to work with both patterns:

```php
// Direct string literals
$stmt = $db->prepare("SELECT ...");

// Variables
$sql = "SELECT ...";
$stmt = $db->prepare($sql);
```

The rules also handle SQL queries prepared in constructors and used in other methods.

## Performance

These rules are designed to be fast:

- Early bailouts for non-SQL code
- Efficient SQL detection heuristics
- Skips very long queries (>10,000 characters)
- Gracefully handles missing dependencies

## Development

To contribute to this project:

1. Clone the repository:
```bash
git clone https://github.com/pierresh/phpstan-pdo-mysql.git
cd phpstan-pdo-mysql
```

2. Install dependencies:
```bash
composer install
```

3. Run tests:
```bash
composer test
```

This will start PHPUnit watcher that automatically runs tests when files change.

To run tests once without watching:
```bash
./vendor/bin/phpunit
```

## License

MIT

## Contributing

Contributions welcome! Please open an issue or submit a pull request.
