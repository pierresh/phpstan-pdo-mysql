<?php

namespace Pierresh\PhpStanPdoMysql\Tests\Fixtures;

use PDO;

/**
 * @phpstan-type User object{id: int, name: string, email: string}
 */
class SelectColumnErrors
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function columnTypo(): void
    {
        $stmt = $this->db->prepare("SELECT id, nam, email FROM users WHERE id = :id");
        $stmt->execute(['id' => 1]);

        /** @var object{id: int, name: string, email: string} */
        $user = $stmt->fetch();
    }

    public function missingColumn(): void
    {
        $stmt = $this->db->prepare("SELECT id, name FROM users WHERE id = :id");
        $stmt->execute(['id' => 1]);

        /** @var object{id: int, name: string, email: string} */
        $user = $stmt->fetch();
    }

    public function extraColumn(): void
    {
        $stmt = $this->db->prepare("SELECT id, name, email, created_at FROM users WHERE id = :id");
        $stmt->execute(['id' => 1]);

        /** @var object{id: int, name: string, email: string} */
        $user = $stmt->fetch();
    }

    public function typeAliasError(): void
    {
        $stmt = $this->db->prepare("SELECT id, nam FROM users WHERE id = :id");
        $stmt->execute(['id' => 1]);

        /** @var User */
        $user = $stmt->fetch();
    }

    public function validColumns(): void
    {
        $stmt = $this->db->prepare("SELECT id, name, email FROM users WHERE id = :id");
        $stmt->execute(['id' => 1]);

        /** @var object{id: int, name: string, email: string} */
        $user = $stmt->fetch();
    }

    public function validWithTypeAlias(): void
    {
        $stmt = $this->db->prepare("SELECT id, name, email FROM users WHERE id = :id");
        $stmt->execute(['id' => 1]);

        /** @var User */
        $user = $stmt->fetch();
    }

    public function variableSqlColumnError(): void
    {
        $sql = "SELECT id, nam FROM users WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => 1]);

        /** @var object{id: int, name: string} */
        $user = $stmt->fetch();
    }
}
