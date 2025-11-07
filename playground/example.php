<?php

// Playground for testing PHPStan PDO MySQL extension
// Open this file in your IDE with PHPStan plugin to see errors highlighted in real-time
// Try modifying the code below and observe how PHPStan catches errors immediately

namespace Playground;

use PDO;

class UserRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ❌ SQL Syntax Error: incomplete SELECT statement
    public function syntaxError(): void
    {
        $stmt = $this->db->query("SELECT * FROM");
    }

    // ❌ Parameter Binding Error: missing :name parameter
    public function parameterError(): void
    {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE id = :id AND name = :name");
        $stmt->execute(['id' => 1]); // Missing :name
    }

    // ❌ SELECT Column Mismatch: typo in column name
    public function columnMismatch(): void
    {
        $stmt = $this->db->prepare("SELECT id, nam, email FROM users WHERE id = :id");
        $stmt->execute(['id' => 1]);

        /** @var object{id: int, name: string, email: string} */
        $user = $stmt->fetch(); // PHPDoc expects 'name' but SELECT has 'nam'
    }

    // ✅ Valid code: no errors
    public function validCode(): void
    {
        $stmt = $this->db->prepare("SELECT id, name, email FROM users WHERE id = :id");
        $stmt->execute(['id' => 1]);

        /** @var object{id: int, name: string, email: string} */
        $user = $stmt->fetch();
    }

    // Try adding your own examples below!
}
