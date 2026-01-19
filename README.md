# PHPStan PDO MySQL Rules

Static analysis rules for PHPStan that validate PDO/MySQL code for common errors that would otherwise only be caught at runtime.

## Features

This extension provides six powerful rules that work without requiring a database connection:

1. **SQL Syntax Validation** - Detects MySQL syntax errors in `prepare()` and `query()` calls
2. **Parameter Binding Validation** - Ensures PDO parameters match SQL placeholders
3. **SELECT Column Validation** - Verifies SELECT columns match PHPDoc type annotations
4. **Self-Reference Detection** - Catches self-reference conditions in JOIN and WHERE clauses
5. **Invalid Table Reference Detection** - Catches typos in table/alias names (e.g., `user.name` when table is `users`)
6. **MySQL-Specific Syntax Detection** - Flags MySQL-specific functions that have portable ANSI alternatives

All validation is performed statically by analyzing your code, so no database setup is needed.

**Developer Tools:**
- **`ddt()` Helper Function** - Generates PHPStan type definitions from runtime values for easy copy-paste into your code
- **`ddc()` Helper Function** - Generates PHP class definitions from objects for use with `PDO::fetchObject()`

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
> SQL syntax error in query(): Expected token NAME ~RESERVED, but end of query found instead.

Works with both direct strings and variables:

```php
$sql = "SELECT * FROM";
$stmt = $db->query($sql);
```

> [!CAUTION]
> SQL syntax error in query(): Expected token NAME ~RESERVED, but end of query found instead.

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
> Missing parameter :name in execute()

```php
// ❌ Extra parameter
$stmt = $db->prepare("SELECT * FROM users WHERE id = :id");
$stmt->execute(['id' => 1, 'extra' => 'unused']);
```

> [!CAUTION]
> Parameter :extra in execute() is not used

```php
// ❌ Wrong parameter name
$stmt = $db->prepare("SELECT * FROM users WHERE id = :user_id");
$stmt->execute(['id' => 1]); // Should be :user_id
```

> [!CAUTION]
> Missing parameter :user_id in execute()
>
> Parameter :id in execute() is not used

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
> Missing parameter :id in execute()
>
> Parameter :name in execute() is not used

### 3. SELECT Column Validation

Validates that SELECT columns match the PHPDoc type annotation.

> [!NOTE]
> This rule supports `fetch()`, `fetchObject()`, and `fetchAll()` methods, assuming the fetch mode of the database connection is `PDO::FETCH_OBJ` (returning objects). Other fetch modes like `PDO::FETCH_ASSOC` (arrays) or `PDO::FETCH_CLASS` are not currently validated.

```php
// ❌ Column typo: "nam" instead of "name"
$stmt = $db->prepare("SELECT id, nam, email FROM users WHERE id = :id");
$stmt->execute(['id' => 1]);

/** @var object{id: int, name: string, email: string} */
$user = $stmt->fetch();
```

> [!CAUTION]
> SELECT column mismatch: PHPDoc expects property "name" but SELECT (line X) has "nam" - possible typo?

```php
// ❌ Missing column
$stmt = $db->prepare("SELECT id, name FROM users WHERE id = :id");
$stmt->execute(['id' => 1]);

/** @var object{id: int, name: string, email: string} */
$user = $stmt->fetch();
```

> [!CAUTION]
> SELECT column missing: PHPDoc expects property "email" but it is not in the SELECT query (line X)

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
$user = $stmt->fetch(); // No error - extra column `created_at` is ignored
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
> SELECT column mismatch: PHPDoc expects property "name" but SELECT (line X) has "nam" - possible typo?
>
> SELECT column missing: PHPDoc expects property "email" but it is not in the SELECT query (line X)

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
> Type mismatch: fetchAll() returns array<object{...}> but PHPDoc specifies object{...} (line X)

```php
// ❌ fetch() returns a single object, not an array
$stmt = $db->prepare("SELECT id, name FROM users WHERE id = :id");
$stmt->execute(['id' => 1]);

/** @var array<object{id: int, name: string}> */
$user = $stmt->fetch(); // Wrong: should be single object type
```

> [!CAUTION]
> Type mismatch: fetch() returns object{...} but PHPDoc specifies array<object{...}> (line X)

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

#### False Return Type Validation

The extension validates that `fetch()` and `fetchObject()` calls properly handle the `false` return value that occurs when no rows are found.

```php
// ❌ Missing |false in type annotation
$stmt = $db->prepare("SELECT id, name FROM users WHERE id = :id");
$stmt->execute(['id' => 1]);

/** @var object{id: int, name: string} */
$user = $stmt->fetch(); // Can return false!
```

> [!CAUTION]
> Missing |false in @var type: fetch() can return false when no results found. Either add |false to the type or check for false/rowCount() before using the result (line X)

```php
// ✅ Correct: Include |false in union type
$stmt = $db->prepare("SELECT id, name FROM users WHERE id = :id");
$stmt->execute(['id' => 1]);

/** @var object{id: int, name: string}|false */
$user = $stmt->fetch();

// Both styles are supported:
/** @var object{id: int, name: string} | false */  // With spaces
/** @var false|object{id: int, name: string} */    // Reverse order
```

```php
// ✅ Correct: Check rowCount() with throw/return
$stmt = $db->prepare("SELECT id, name FROM users WHERE id = :id");
$stmt->execute(['id' => 1]);

if ($stmt->rowCount() === 0) {
    throw new \RuntimeException('User not found');
}

/** @var object{id: int, name: string} */
$user = $stmt->fetch(); // Safe - won't execute if no rows
```

```php
// ✅ Correct: Check for false after fetch
$stmt = $db->prepare("SELECT id, name FROM users WHERE id = :id");
$stmt->execute(['id' => 1]);

/** @var object{id: int, name: string} */
$user = $stmt->fetch();

if ($user === false) {
    throw new \RuntimeException('User not found');
}
// Or: if ($user !== false) { ... }
// Or: if (!$user) { ... }
```

```php
// ❌ rowCount() without throw/return doesn't help
$stmt = $db->prepare("SELECT id, name FROM users WHERE id = :id");
$stmt->execute(['id' => 1]);

if ($stmt->rowCount() === 0) {
    // Empty block - execution continues!
}

/** @var object{id: int, name: string} */
$user = $stmt->fetch(); // Still can return false!
```

> [!CAUTION]
> Missing |false in @var type: fetch() can return false when no results found. Either add |false to the type or check for false/rowCount() before using the result (line X)

> [!NOTE]
> This validation applies only to `fetch()` and `fetchObject()`. The `fetchAll()` method returns an empty array instead of false, so it doesn't require `|false` in the type annotation.

### 4. Self-Reference Detection

Detects self-reference conditions where the same column is compared to itself. This is likely a bug where the developer meant to reference a different table or column.

```php
// ❌ Self-reference in JOIN condition
$stmt = $db->prepare("
    SELECT *
    FROM orders
    INNER JOIN users ON users.id = users.id
");
```

> [!CAUTION]
> Self-referencing JOIN condition: 'users.id = users.id'

```php
// ❌ Self-reference in WHERE clause
$stmt = $db->prepare("
    SELECT *
    FROM products
    WHERE products.category_id = products.category_id
");
```

> [!CAUTION]
> Self-referencing WHERE condition: 'products.category_id = products.category_id'

```php
// ❌ Multiple self-references in same query
$stmt = $db->prepare("
    SELECT *
    FROM orders
    INNER JOIN products ON products.id = products.id
    WHERE products.active = products.active
");
```

> [!CAUTION]
> Self-referencing JOIN condition: 'products.id = products.id'
>
> Self-referencing WHERE condition: 'products.active = products.active'

```php
// ✅ Valid JOIN - different columns
$stmt = $db->prepare("
    SELECT *
    FROM orders
    INNER JOIN users ON orders.user_id = users.id
");

// ✅ Valid WHERE - comparing to a value
$stmt = $db->prepare("
    SELECT *
    FROM products
    WHERE products.category_id = 5
");
```

> [!NOTE]
> This rule works with:
> - `INNER JOIN`, `LEFT JOIN`, `RIGHT JOIN` conditions
> - `WHERE` clause conditions (including `AND`/`OR` combinations)
> - Both `SELECT` and `INSERT...SELECT` queries
> - Queries with PDO placeholders (`:parameter`)

The rule reports errors on the exact line where the self-reference occurs, making it easy to locate and fix the issue.

### 5. Invalid Table Reference Detection

Detects typos in table and alias names used in qualified column references. Catches errors like using `user.name` when the table is `users`, or referencing a table that doesn't appear in FROM/JOIN clauses.

```php
// ❌ Table 'user' doesn't exist - should be 'users'
$stmt = $db->prepare("SELECT user.name FROM users WHERE users.id = :id");
```

> [!CAUTION]
> Invalid table reference 'user' - available tables/aliases: users

```php
// ❌ Wrong alias - using 'usr' but alias is 'u'
$stmt = $db->prepare("SELECT usr.name FROM users AS u WHERE u.id = :id");
```

> [!CAUTION]
> Invalid table reference 'usr' - available tables/aliases: u, users

```php
// ❌ Table 'orders' not in FROM or JOIN
$stmt = $db->prepare("SELECT users.id, orders.total FROM users WHERE users.id = :id");
```

> [!CAUTION]
> Invalid table reference 'orders' - available tables/aliases: users

```php
// ✅ Correct table name
$stmt = $db->prepare("SELECT users.name FROM users WHERE users.id = :id");

// ✅ Correct alias usage
$stmt = $db->prepare("SELECT u.name FROM users AS u WHERE u.id = :id");

// ✅ Both table name and alias can be used
$stmt = $db->prepare("SELECT users.id, u.name FROM users AS u WHERE u.id = :id");

// ✅ Multiple tables with JOIN
$stmt = $db->prepare("
    SELECT u.name, o.total
    FROM users AS u
    INNER JOIN orders AS o ON u.id = o.user_id
    WHERE u.id = :id
");
```

The rule validates:
- Column references in SELECT clause
- Column references in WHERE conditions
- Column references in JOIN conditions
- Column references in ORDER BY and GROUP BY clauses
- Column references in HAVING clause

This catches common typos that would only be discovered at runtime, like:
- Singular/plural mistakes (`user` vs `users`)
- Typos in alias names (`usr` vs `usrs`)
- Wrong table references in complex JOINs

### 6. MySQL-Specific Syntax Detection

Detects MySQL-specific SQL syntax that has portable ANSI alternatives. This helps maintain database-agnostic code for future migrations to PostgreSQL, SQL Server, or other databases.

```php
// ❌ IFNULL is MySQL-specific
$stmt = $db->prepare("SELECT IFNULL(name, 'Unknown') FROM users");
```

> [!CAUTION]
> Use COALESCE() instead of IFNULL() for database portability

```php
// ❌ IF() is MySQL-specific
$stmt = $db->prepare("SELECT IF(status = 1, 'Active', 'Inactive') FROM users");
```

> [!CAUTION]
> Use CASE WHEN instead of IF() for database portability

```php
// ✅ COALESCE is portable (works in MySQL, PostgreSQL, SQL Server)
$stmt = $db->prepare("SELECT COALESCE(name, 'Unknown') FROM users");

// ✅ CASE WHEN is portable
$stmt = $db->prepare("SELECT CASE WHEN status = 1 THEN 'Active' ELSE 'Inactive' END FROM users");
```

```php
// ❌ NOW() is MySQL-specific
$stmt = $db->prepare("SELECT * FROM users WHERE created_at > NOW()");
```

> [!CAUTION]
> Bind current datetime to a PHP variable instead of NOW() for database portability

```php
// ❌ CURDATE() is MySQL-specific
$stmt = $db->prepare("SELECT * FROM users WHERE birth_date = CURDATE()");
```

> [!CAUTION]
> Bind current date to a PHP variable instead of CURDATE() for database portability

```php
// ❌ LIMIT offset, count is MySQL-specific
$stmt = $db->prepare("SELECT * FROM users LIMIT 10, 5");
```

> [!CAUTION]
> Use LIMIT count OFFSET offset instead of LIMIT offset, count for database portability

```php
// ✅ Bind PHP datetime variables
$stmt = $db->prepare("SELECT * FROM users WHERE created_at > :now");
$stmt->execute(['now' => (new \DateTime())->format('Y-m-d H:i:s')]);

$stmt = $db->prepare("SELECT * FROM users WHERE birth_date = :today");
$stmt->execute(['today' => (new \DateTime())->format('Y-m-d')]);

// ✅ LIMIT count OFFSET offset is portable
$stmt = $db->prepare("SELECT * FROM users LIMIT 5 OFFSET 10");
```

Currently detects:
- `IFNULL()` → Use `COALESCE()`
- `IF()` → Use `CASE WHEN`
- `NOW()` → Bind PHP datetime variable
- `CURDATE()` → Bind PHP date variable
- `LIMIT offset, count` → Use `LIMIT count OFFSET offset`

## Requirements

- PHP 8.1+
- PHPStan 1.10+
- SQLFTW 0.1+ (SQL syntax validation)

## How It Works

All four rules use a two-pass analysis approach:

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

## Known Limitations

- SQL queries with variable interpolation (e.g., `"SELECT $column FROM table"`) cannot be validated
- `SELECT *` and `SELECT table.*` queries cannot be validated for column matching (no way to know columns statically)
- Very long queries (>10,000 characters) are skipped for performance
- Cross-file SQL tracking is limited to class properties

## Performance

These rules are designed to be fast:

- Early bailouts for non-SQL code
- Efficient SQL detection heuristics
- Skips very long queries (>10,000 characters)
- Gracefully handles missing dependencies

## Available Error Identifiers

| Identifier | Rule | Description |
|------------|------|-------------|
| `pdoSql.sqlSyntax` | SQL Syntax Validation | SQL syntax error detected |
| `pdoSql.missingParameter` | Parameter Bindings | Parameter expected in SQL but missing from `execute()` array |
| `pdoSql.extraParameter` | Parameter Bindings | Parameter in `execute()` array but not used in SQL |
| `pdoSql.missingBinding` | Parameter Bindings | Parameter expected but no `bindValue()`/`bindParam()` found |
| `pdoSql.extraBinding` | Parameter Bindings | Parameter bound but not used in SQL |
| `pdoSql.columnMismatch` | SELECT Column Validation | Column name typo detected (case-sensitive) |
| `pdoSql.columnMissing` | SELECT Column Validation | PHPDoc property missing from SELECT  |
| `pdoSql.fetchTypeMismatch` | SELECT Column Validation | Fetch method doesn't match PHPDoc type structure |
| `pdoSql.missingFalseType` | SELECT Column Validation | Missing `\|false` union type for `fetch()`/`fetchObject()` |
| `pdoSql.selfReferenceCondition` | Self-Reference Detection | Self-referencing condition in JOIN or WHERE clause |
| `pdoSql.invalidTableReference` | Invalid Table Reference Detection | Invalid table or alias name in qualified column reference |
| `pdoSql.mySqlSpecific` | MySQL-Specific Syntax | MySQL-specific function with portable alternative |

### Ignoring Specific Errors

All errors from this extension have custom identifiers that allow you to selectively ignore them in your `phpstan.neon`:

```neon
parameters:
    ignoreErrors:
        # Ignore all SQL syntax errors
        - identifier: pdoSql.sqlSyntax

        # Ignore all parameter binding errors
        - identifier: pdoSql.missingParameter
        - identifier: pdoSql.extraParameter
        - identifier: pdoSql.missingBinding
        - identifier: pdoSql.extraBinding

        # Ignore all SELECT column validation errors
        - identifier: pdoSql.columnMismatch
        - identifier: pdoSql.columnMissing
        - identifier: pdoSql.fetchTypeMismatch
        - identifier: pdoSql.missingFalseType

        # Ignore all self-reference detection errors
        - identifier: pdoSql.selfReferenceCondition

        # Ignore all invalid table reference detection errors
        - identifier: pdoSql.invalidTableReference

        # Ignore all MySQL-specific syntax errors
        - identifier: pdoSql.mySqlSpecific
```

You can also ignore errors by path or message pattern:

```neon
parameters:
    ignoreErrors:
        # Ignore SQL syntax errors in migration files
        -
            identifier: pdoSql.sqlSyntax
            path: */migrations/*

        # Ignore missing parameter errors for a specific parameter
        -
            message: '#Missing parameter :legacy_id#'
            identifier: pdoSql.missingParameter
```

## Playground

Want to try the extension quickly? Open `playground/example.php` in your IDE with a PHPStan plugin installed. You'll see errors highlighted in real-time as you edit the code.

## Developer Tools

### `ddt()` - Dump Debug Type

The `ddt()` helper function inspects PHP values at runtime and generates PHPStan type definitions. This is useful for quickly creating `@phpstan-type` annotations from real data in tests.

**Usage in PHPUnit tests:**

```php
use PHPUnit\Framework\TestCase;

class MyTest extends TestCase
{
    public function testExample(): void
    {
        $row = $stmt->fetch(); // Fetch data from database
        ddt($row); // Dumps type and stops execution
    }
}
```

**Terminal output:**

```php
/**
 * @phpstan-type Item object{
 *  id: int,
 *  name: string,
 *  status: int,
 * }
 */
```

Simply copy the output and paste it into your code as a type annotation!

**Supported types:**

- **Objects** (stdClass and class instances): Shows public properties as `object{...}` shape
- **Associative arrays**: Formatted as `array{key: type, ...}`
- **Sequential arrays**: Formatted as `array<int, type>`
- **Nested structures**: Handles nesting up to 5 levels deep
- **All scalar types**: int, float, string, bool, null

**Type mapping:**

| PHP Runtime Type | PHPStan Output |
|-----------------|----------------|
| `integer` | `int` |
| `double` | `float` |
| `string` | `string` |
| `boolean` | `bool` |
| `NULL` | `null` |
| `array` (associative) | `array{key: type, ...}` |
| `array` (sequential) | `array<int, type>` |
| `object` | `object{prop: type, ...}` |

**Examples:**

```php
// Nested objects
$workflow = new stdClass();
$workflow->id = 1;
$workflow->metadata = new stdClass();
$workflow->metadata->created_at = '2024-01-01';

ddt($workflow);

// Output:
/**
 * @phpstan-type Item object{
 *  id: int,
 *  metadata: object{
 *    created_at: string,
 *  },
 * }
 */
```

```php
// Associative array
$config = ['database' => 'mysql', 'port' => 3306];
ddt($config);

// Output:
/**
 * @phpstan-type Item array{
 *  database: string,
 *  port: int,
 * }
 */
```

```php
// Sequential array
$ids = [1, 2, 3, 4, 5];
ddt($ids);

// Output:
/**
 * @phpstan-type Item array<int, int>
 */
```

**Note:** The function calls `exit(0)` after dumping (like `dd()`), so execution stops. This is intentional for use in debugging/testing workflows.

### `ddc()` - Dump Debug Class

The `ddc()` helper function inspects PHP objects at runtime and generates PHP class definitions. This is useful for creating view model classes compatible with `PDO::fetchObject()`.

**Usage in PHPUnit tests:**

```php
use PHPUnit\Framework\TestCase;

class MyTest extends TestCase
{
    public function testExample(): void
    {
        $row = $stmt->fetchObject(); // Fetch data from database
        ddc($row); // Dumps class definition and stops execution
    }
}
```

**Terminal output:**

```php
class Item
{
    public int $id;
    public string $name;
    public string $email;
    public ?string $phone;
}
```

Simply copy the output, rename the class, and use it as your view model!

**Example workflow:**

```php
// 1. First, discover the structure using ddc()
$stmt = $db->query("SELECT id, name, email, phone FROM users WHERE id = 1");
$row = $stmt->fetchObject();
ddc($row);

// 2. Create your view model class from the output
class UserViewModel
{
    public int $id;
    public string $name;
    public string $email;
    public ?string $phone;
}

// 3. Use it with PDO::fetchObject()
$stmt = $db->query("SELECT id, name, email, phone FROM users WHERE id = 1");
$user = $stmt->fetchObject(UserViewModel::class);
```

**Supported types:**

| PHP Runtime Value | Generated Type |
|------------------|----------------|
| `integer` | `int` |
| `double` | `float` |
| `string` | `string` |
| `boolean` | `bool` |
| `NULL` | `mixed` |
| `array` | `array` |
| `object` | `object` |

**Note:** Like `ddt()`, this function calls `exit(0)` after dumping.

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

4. Analyze source code with PHPStan:
```bash
composer analyze
```

This analyzes only the `./src` directory (excludes playground and test fixtures) at maximum level.

5. Refactor code with Rector:
```bash
composer refactor:dry  # Preview changes without applying
composer refactor      # Apply refactoring changes
```

Rector is configured to modernize code to PHP 8.1+ standards with code quality improvements.

6. Format code with Mago:
```bash
composer format:check  # Check formatting without making changes
composer format        # Apply code formatting
```

Mago provides consistent, opinionated code formatting for PHP 8.1+.

7. Lint code with Mago:
```bash
composer lint          # Run Mago linter
```

8. Analyze code with Mago:
```bash
composer mago:analyze  # Run Mago static analyzer
```

Mago's analyzer provides fast, type-level analysis to find logical errors and type mismatches.

## License

MIT

## Contributing

Contributions welcome! Please open an issue or submit a pull request.
