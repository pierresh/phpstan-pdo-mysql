<?php

// Playground for testing the "mssql" (T-SQL) dialect - see README.md "SQL Dialect Support"
// Run with: vendor/bin/phpstan analyze playground/example-mssql.php -c playground-mssql.neon
// Or point your IDE's PHPStan plugin config at playground-mssql.neon to see errors live.
//
// Note: only Rule 1 (SQL syntax validation) is dialect-aware. The other rules in
// playground-mssql.neon still parse SQL as MySQL via SQLFTW - see README "Known Limitations".

namespace Playground;

use PDO;

class OrderRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ✅ Valid T-SQL: TOP, bracketed identifiers, PDO placeholder
    public function validTSql(): void
    {
        $stmt = $this->db->prepare("SELECT TOP 10 [id], [name] FROM [dbo].[orders] WHERE [id] = :id");
        $stmt->execute(['id' => 1]);
    }
}
