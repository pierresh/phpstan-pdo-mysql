<?php

namespace Pierresh\PhpStanPdoMysql\Tests\Fixtures;

use PDO;

class TautologicalConditions
{
	private PDO $db;

	public function __construct(PDO $db)
	{
		$this->db = $db;
	}

	// ======================================
	// ALWAYS-TRUE CONDITIONS (should error)
	// ======================================

	public function whereNumericTautology(): void
	{
		// Error: 1 = 1 is always true
		$stmt = $this->db->prepare('
            SELECT *
            FROM users
            WHERE 1 = 1
        ');
		$stmt->execute();
	}

	public function whereZeroTautology(): void
	{
		// Error: 0 = 0 is always true
		$stmt = $this->db->prepare('
            SELECT *
            FROM users
            WHERE 0 = 0
        ');
		$stmt->execute();
	}

	public function whereOtherNumericTautology(): void
	{
		// Error: 42 = 42 is always true
		$stmt = $this->db->prepare('
            SELECT *
            FROM users
            WHERE 42 = 42
        ');
		$stmt->execute();
	}

	public function whereStringTautology(): void
	{
		// Error: 'yes' = 'yes' is always true
		$stmt = $this->db->prepare("
            SELECT *
            FROM users
            WHERE 'yes' = 'yes'
        ");
		$stmt->execute();
	}

	public function whereTrueTautology(): void
	{
		// Error: TRUE = TRUE is always true
		$stmt = $this->db->prepare('
            SELECT *
            FROM users
            WHERE TRUE = TRUE
        ');
		$stmt->execute();
	}

	public function whereFalseTautology(): void
	{
		// Error: FALSE = FALSE is always true
		$stmt = $this->db->prepare('
            SELECT *
            FROM users
            WHERE FALSE = FALSE
        ');
		$stmt->execute();
	}

	public function joinTautology(): void
	{
		// Error: 1 = 1 in JOIN condition
		$stmt = $this->db->prepare('
            SELECT *
            FROM users
            INNER JOIN orders ON 1 = 1
        ');
		$stmt->execute();
	}

	public function havingTautology(): void
	{
		// Error: 1 = 1 in HAVING clause
		$stmt = $this->db->prepare('
            SELECT COUNT(*) as cnt
            FROM users
            GROUP BY status
            HAVING 1 = 1
        ');
		$stmt->execute();
	}

	public function whereWithAndTautology(): void
	{
		// Error: Tautology within AND condition
		$stmt = $this->db->prepare('
            SELECT *
            FROM users
            WHERE status = \'active\' AND 1 = 1
        ');
		$stmt->execute();
	}

	public function variableSqlWithTautology(): void
	{
		// Error: Tautology in variable-based SQL
		$sql = '
            SELECT *
            FROM products
            WHERE 1 = 1
        ';
		$stmt = $this->db->prepare($sql);
		$stmt->execute();
	}

	// ======================================
	// ALWAYS-FALSE CONDITIONS (should error)
	// ======================================

	public function whereAlwaysFalse(): void
	{
		// Error: 1 = 0 is always false
		$stmt = $this->db->prepare('
            SELECT *
            FROM users
            WHERE 1 = 0
        ');
		$stmt->execute();
	}

	public function whereStringAlwaysFalse(): void
	{
		// Error: 'a' = 'b' is always false
		$stmt = $this->db->prepare("
            SELECT *
            FROM users
            WHERE 'a' = 'b'
        ");
		$stmt->execute();
	}

	public function whereBooleanAlwaysFalse(): void
	{
		// Error: TRUE = FALSE is always false
		$stmt = $this->db->prepare('
            SELECT *
            FROM users
            WHERE TRUE = FALSE
        ');
		$stmt->execute();
	}

	// ======================================
	// VALID CASES (should NOT error)
	// ======================================

	public function whereWithParameter(): void
	{
		// Valid: Literal with parameter
		$stmt = $this->db->prepare('
            SELECT *
            FROM users
            WHERE 1 = :id
        ');
		$stmt->execute(['id' => 1]);
	}

	public function whereColumnWithLiteral(): void
	{
		// Valid: Column compared with literal
		$stmt = $this->db->prepare('
            SELECT *
            FROM users
            WHERE user_id = 1
        ');
		$stmt->execute();
	}

	public function whereColumnWithString(): void
	{
		// Valid: Column compared with string
		$stmt = $this->db->prepare("
            SELECT *
            FROM users
            WHERE status = 'active'
        ");
		$stmt->execute();
	}

	public function whereColumnsCompared(): void
	{
		// Valid: Two columns compared
		$stmt = $this->db->prepare('
            SELECT *
            FROM users
            WHERE created_at = updated_at
        ');
		$stmt->execute();
	}

	public function joinWithRealCondition(): void
	{
		// Valid: Proper JOIN condition
		$stmt = $this->db->prepare('
            SELECT *
            FROM users
            INNER JOIN orders ON users.id = orders.user_id
        ');
		$stmt->execute();
	}
}
