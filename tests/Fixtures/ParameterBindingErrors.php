<?php

namespace Pierresh\PhpStanPdoMysql\Tests\Fixtures;

use PDO;

class ParameterBindingErrors
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function missingParameter(): void
    {
        // SQL has :id and :name, but execute only provides :id
        $stmt = $this->db->prepare("SELECT * FROM users WHERE id = :id AND name = :name");
        $stmt->execute(['id' => 1]); // Missing :name
    }

    public function extraParameter(): void
    {
        // SQL has :id, but execute provides :id and :extra
        $stmt = $this->db->prepare("SELECT * FROM users WHERE id = :id");
        $stmt->execute(['id' => 1, 'extra' => 'unused']); // Extra :extra
    }

    public function executeOverridesBindValue(): void
    {
        // bindValue is ignored when execute() has array params
        $stmt = $this->db->prepare("SELECT * FROM users WHERE id = :id");
        $stmt->bindValue(':id', 1); // This is ignored
        $stmt->execute(['name' => 'John']); // Missing :id, has wrong :name
    }

    public function validBinding(): void
    {
        // This should NOT report any error
        $stmt = $this->db->prepare("SELECT * FROM users WHERE id = :id AND name = :name");
        $stmt->execute(['id' => 1, 'name' => 'John']);
    }

    public function validBindValue(): void
    {
        // This should NOT report any error
        $stmt = $this->db->prepare("SELECT * FROM users WHERE id = :id");
        $stmt->bindValue(':id', 1);
        $stmt->execute();
    }

    public function variableSqlMismatch(): void
    {
        // SQL in variable with parameter mismatch
        $sql = "SELECT * FROM users WHERE id = :user_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => 1]); // Wrong parameter name
    }
}
