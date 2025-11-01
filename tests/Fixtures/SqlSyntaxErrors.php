<?php

namespace Pierresh\PhpStanPdoMysql\Tests\Fixtures;

use PDO;

class SqlSyntaxErrors
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function queryMethodError(): void
    {
        // Invalid SQL in query() method - incomplete statement
        $stmt = $this->db->query("SELECT * FROM");
    }

    public function validSql(): void
    {
        // This should NOT report any error
        $stmt = $this->db->prepare("SELECT id, name FROM users WHERE id = :id");
        $stmt->execute(['id' => 1]);
    }
}
