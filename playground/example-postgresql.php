<?php

// Playground for testing the "postgresql" dialect - see README.md "SQL Dialect Support"
// Run with: vendor/bin/phpstan analyze playground/example-postgresql.php -c playground-postgresql.neon --level=max
// Or point your IDE's PHPStan plugin config at playground-postgresql.neon to see errors live.
//
// Note: only Rule 1 (SQL syntax validation) is dialect-aware. The other rules in
// playground-postgresql.neon still parse SQL as MySQL via SQLFTW - see README "Known Limitations".

namespace Playground;

use PDO;

class ProductRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ✅ Valid PostgreSQL: double-quoted identifiers, ILIKE, PDO placeholder
    public function validPostgres(): void
    {
        $stmt = $this->db->prepare('SELECT "id", "name" FROM "products" WHERE "name" ILIKE :name');
        $stmt->execute(['name' => '%widget%']);
    }

    // ❌ SQL Syntax Error: unterminated double-quoted identifier (Postgres specific)
    public function unterminatedQuotedIdentifier(): void
    {
        $stmt = $this->db->query('SELECT "id, name FROM products');
    }

    // ❌ SQL Syntax Error: incomplete SELECT statement
    public function incompleteQuery(): void
    {
        $stmt = $this->db->query("SELECT * FROM");
    }

    // ✅ Valid PostgreSQL: JSONB operators, RETURNING clause, ON CONFLICT upsert
    public function validJsonbAndUpsert(): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO products (id, name) VALUES (:id, :name) ON CONFLICT (id) DO UPDATE SET name = EXCLUDED.name RETURNING id",
        );
        $stmt->execute(['id' => 1, 'name' => 'widget']);

        $stmt = $this->db->query("SELECT data->>'name' AS name FROM products WHERE data @> '{\"active\": true}'");
    }

    // Try adding your own PostgreSQL examples below!
}
