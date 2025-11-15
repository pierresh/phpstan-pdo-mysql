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
    // ❌ Missing |false: fetch() can return false
    public function columnMismatch(): void
    {
        $stmt = $this->db->prepare("SELECT id, nam, email FROM users WHERE id = :id");
        $stmt->execute(['id' => 1]);

        /** @var object{id: int, name: string, email: string} */
        $user = $stmt->fetch(); // PHPDoc expects 'name' but SELECT has 'nam'
    }

    // ❌ Missing |false in type annotation
    public function missingFalseType(): void
    {
        $stmt = $this->db->prepare("SELECT id, name, email FROM users WHERE id = :id");
        $stmt->execute(['id' => 1]);

        /** @var object{id: int, name: string, email: string} */
        $user = $stmt->fetch(); // Can return false when no rows found!
    }

    // ✅ Valid: |false included in type annotation
    public function validWithFalseType(): void
    {
        $stmt = $this->db->prepare("SELECT id, name, email FROM users WHERE id = :id");
        $stmt->execute(['id' => 1]);

        /** @var object{id: int, name: string, email: string}|false */
        $user = $stmt->fetch();
    }

    // ✅ Valid: |false with spaces (both styles supported)
    public function validWithFalseTypeSpaced(): void
    {
        $stmt = $this->db->prepare("SELECT id, name, email FROM users WHERE id = :id");
        $stmt->execute(['id' => 1]);

        /** @var object{id: int, name: string, email: string} | false */
        $user = $stmt->fetch();
    }

    // ✅ Valid: rowCount() check with throw
    public function validWithRowCountCheck(): void
    {
        $stmt = $this->db->prepare("SELECT id, name, email FROM users WHERE id = :id");
        $stmt->execute(['id' => 1]);

        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException('User not found');
        }

        /** @var object{id: int, name: string, email: string} */
        $user = $stmt->fetch(); // Safe - won't execute if no rows
    }

    // ✅ Valid: false check after fetch
    public function validWithFalseCheck(): void
    {
        $stmt = $this->db->prepare("SELECT id, name, email FROM users WHERE id = :id");
        $stmt->execute(['id' => 1]);

        /** @var object{id: int, name: string, email: string}|false */
        $user = $stmt->fetch();

        if ($user === false) {
            throw new \RuntimeException('User not found');
        }
    }

    // ❌ rowCount() without throw/return doesn't help
    public function invalidRowCountNoThrow(): void
    {
        $stmt = $this->db->prepare("SELECT id, name, email FROM users WHERE id = :id");
        $stmt->execute(['id' => 1]);

        if ($stmt->rowCount() === 0) {
            // Empty block - execution continues!
        }

        /** @var object{id: int, name: string, email: string} */
        $user = $stmt->fetch(); // Still can return false!
    }

    // ✅ fetchAll() doesn't need |false (returns empty array)
    public function fetchAllValid(): void
    {
        $stmt = $this->db->prepare("SELECT id, name, email FROM users");
        $stmt->execute();

        /** @var array<object{id: int, name: string, email: string}> */
        $users = $stmt->fetchAll(); // No |false needed
    }

    // ✅ SELECT * is allowed (cannot be validated statically)
    public function selectStarAllowed(): void
    {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE id = :id");
        $stmt->execute(['id' => 1]);

        /** @var object{id: int, name: string, email: string}|false */
        $user = $stmt->fetch();
    }

    // ✅ SELECT table.* is allowed (cannot be validated statically)
    public function selectTableStarAllowed(): void
    {
        $stmt = $this->db->prepare("SELECT users.* FROM users WHERE id = :id");
        $stmt->execute(['id' => 1]);

        /** @var object{id: int, name: string, email: string}|false */
        $user = $stmt->fetch();
    }

    // Try adding your own examples below!
}
