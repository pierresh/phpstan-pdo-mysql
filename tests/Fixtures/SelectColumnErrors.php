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

    public function multipleQueriesVariableBasedMatching(): void
    {
        // First query: only has 2 columns (id, name)
        $stmt = $this->db->prepare("SELECT id, name FROM users WHERE id = :id");

        // Second query: has all 3 columns (id, name, email)
        $stmt3 = $this->db->prepare("SELECT id, name, email FROM users WHERE id = :id");
        $stmt3->execute(['id' => 1]);

        // This @var expects 3 columns but uses $stmt (which only has 2 columns)
        // Variable-based matching should detect this mismatch
        /** @var object{id: int, name: string, email: string} */
        $user = $stmt->fetch();
    }

    public function multipleQueriesVariableBasedMatchingCorrect(): void
    {
        // First query: only has 2 columns (id, name)
        $stmt = $this->db->prepare("SELECT id, name FROM users WHERE id = :id");

        // Second query: has all 3 columns (id, name, email)
        $stmt3 = $this->db->prepare("SELECT id, name, email FROM users WHERE id = :id");
        $stmt3->execute(['id' => 1]);

        // This @var expects 3 columns and correctly uses $stmt3 (which has 3 columns)
        // Variable-based matching should NOT error
        /** @var object{id: int, name: string, email: string} */
        $user = $stmt3->fetch();
    }

    public function selectStarShouldNotError(): void
    {
        // SELECT * cannot be validated statically, so it should be silently skipped
        // This should NOT produce an error
        $stmt = $this->db->prepare("SELECT * FROM users WHERE id = :id");
        $stmt->execute(['id' => 1]);

        /** @var object{id: int, name: string, email: string} */
        $user = $stmt->fetch();
    }
}
