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
// ❌ Incomplete query
$stmt = $db->query("SELECT * FROM");
```

> [!CAUTION]
> Error: SQL syntax error in query(): Expected token NAME ~RESERVED, but end of query found instead.

```php
// ❌ Trailing comma in VALUES
$stmt = $db->prepare("
    INSERT INTO users (id, name, email)
    VALUES (1, 'John', 'john@example.com',)
");
```

> [!CAUTION]
> Error: SQL syntax error in prepare(): Expected token NAME|VALUE, but token SYMBOL with value ")" found instead.

Works with both direct strings and variables:

```php
$sql = "SELECT * FROM";
$stmt = $db->query($sql);
```

> [!CAUTION]
> Error: SQL syntax error in query(): Expected token NAME ~RESERVED, but end of query found instead.

```php
// ✅ Valid SQL
$stmt = $db->prepare("SELECT id, name FROM users WHERE id = :id");
```

### 2. Parameter Binding Validation

Ensures all SQL placeholders have corresponding bindings:

```php
// ❌ Missing parameter
$stmt = $db->prepare("SELECT * FROM users WHERE id = :id AND name = :name");
$stmt->execute(['id' => 1]); // Missing :name
```

> [!CAUTION]
> Error: Missing parameter :name in execute() array - SQL query (line X) expects this parameter

```php
// ❌ Extra parameter
$stmt = $db->prepare("SELECT * FROM users WHERE id = :id");
$stmt->execute(['id' => 1, 'extra' => 'unused']);
```

> [!CAUTION]
> Error: Parameter :extra in execute() array is not used in SQL query (line X)

```php
// ❌ Wrong parameter name
$stmt = $db->prepare("SELECT * FROM users WHERE id = :user_id");
$stmt->execute(['id' => 1]); // Should be :user_id
```

> [!CAUTION]
> Error: Missing parameter :user_id in execute() array - SQL query (line X) expects this parameter
> Error: Parameter :id in execute() array is not used in SQL query (line X)

```php
// ✅ Valid bindings
$stmt = $db->prepare("SELECT * FROM users WHERE id = :id AND name = :name");
$stmt->execute(['id' => 1, 'name' => 'John']);
```

Important: When `execute()` receives an array, it ignores previous `bindValue()` calls:

```php
$stmt = $db->prepare("SELECT * FROM users WHERE id = :id");
$stmt->bindValue(':id', 1); // This is ignored!
$stmt->execute(['name' => 'John']); // Wrong parameter
```

> [!CAUTION]
> Error: Missing parameter :id in execute() array - SQL query (line X) expects this parameter
> Error: Parameter :name in execute() array is not used in SQL query (line X)

### 3. SELECT Column Validation

Validates that SELECT columns match the PHPDoc type annotation.

> [!NOTE]
> This rule supports `fetch()`, `fetchObject()`, and `fetchAll()` methods, assuming the fetch mode is `PDO::FETCH_OBJ` (returning objects). Other fetch modes like `PDO::FETCH_ASSOC` (arrays) or `PDO::FETCH_CLASS` are not currently validated.

```php
// ❌ Column typo: "nam" instead of "name"
$stmt = $db->prepare("SELECT id, nam, email FROM users WHERE id = :id");
$stmt->execute(['id' => 1]);

/** @var object{id: int, name: string, email: string} */
$user = $stmt->fetch();
```

> [!CAUTION]
> Error: SELECT column mismatch: PHPDoc expects property "name" but SELECT (line X) has "nam" - possible typo?

```php
// ❌ Missing column
$stmt = $db->prepare("SELECT id, name FROM users WHERE id = :id");
$stmt->execute(['id' => 1]);

/** @var object{id: int, name: string, email: string} */
$user = $stmt->fetch();
```

> [!CAUTION]
> Error: SELECT column missing: PHPDoc expects property "email" but it is not in the SELECT query (line X)

```php
// ✅ Valid columns
$stmt = $db->prepare("SELECT id, name, email FROM users WHERE id = :id");
$stmt->execute(['id' => 1]);

/** @var object{id: int, name: string, email: string} */
$user = $stmt->fetch();

// ✅ Also valid - selecting extra columns is fine
$stmt = $db->prepare("SELECT id, name, email, created_at FROM users WHERE id = :id");
$stmt->execute(['id' => 1]);

/** @var object{id: int, name: string, email: string} */
$user = $stmt->fetch(); // No error - extra columns are ignored
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
        // Typo: "nam" instead of "name", also missing "email"
        $stmt = $this->db->prepare("SELECT id, nam FROM users WHERE id = :id");
        $stmt->execute(['id' => $id]);

        /** @var User */
        $user = $stmt->fetch();
```

> [!CAUTION]
> Error: SELECT column mismatch: PHPDoc expects property "name" but SELECT (line X) has "nam" - possible typo?
> Error: SELECT column missing: PHPDoc expects property "email" but it is not in the SELECT query (line X)

```php
    }
}
```

#### Fetch Method Type Validation

The extension also validates that your PHPDoc type structure matches the fetch method being used:

```php
// ❌ fetchAll() returns an array of objects, not a single object
$stmt = $db->prepare("SELECT id, name FROM users");
$stmt->execute();

/** @var object{id: int, name: string} */
$users = $stmt->fetchAll(); // Wrong: should be array type
```

> [!CAUTION]
> Error: Type mismatch: fetchAll() returns array<object{...}> but PHPDoc specifies object{...} (line X)

```php
// ❌ fetch() returns a single object, not an array
$stmt = $db->prepare("SELECT id, name FROM users WHERE id = :id");
$stmt->execute(['id' => 1]);

/** @var array<object{id: int, name: string}> */
$user = $stmt->fetch(); // Wrong: should be single object type
```

> [!CAUTION]
> Error: Type mismatch: fetch() returns object{...} but PHPDoc specifies array<object{...}> (line X)

```php
// ✅ Correct: fetchAll() with array type (generic syntax)
$stmt = $db->prepare("SELECT id, name FROM users");
$stmt->execute();

/** @var array<object{id: int, name: string}> */
$users = $stmt->fetchAll();

// ✅ Correct: fetchAll() with array type (suffix syntax)
/** @var object{id: int, name: string}[] */
$users = $stmt->fetchAll();

// ✅ Correct: fetch() with single object type
$stmt = $db->prepare("SELECT id, name FROM users WHERE id = :id");
$stmt->execute(['id' => 1]);

/** @var object{id: int, name: string} */
$user = $stmt->fetch();
```

> [!NOTE]
> Both PHPStan array syntaxes are supported:
> - Generic syntax: `array<object{...}>`
> - Suffix syntax: `object{...}[]`

## Requirements

- PHP 8.1+
- PHPStan 1.10+
- SQLFTW 0.1+ (SQL syntax validation)

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

## Playground

Want to try the extension quickly? Open `playground/example.php` in your IDE with a PHPStan plugin installed. You'll see errors highlighted in real-time as you edit the code.

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
