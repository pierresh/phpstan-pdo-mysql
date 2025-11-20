<?php

namespace Pierresh\PhpStanPdoMysql\Tests\Fixtures;

use PDO;

class MySqlSpecificSyntaxErrors
{
	private PDO $db;

	public function __construct(PDO $db)
	{
		$this->db = $db;
	}

	public function usingIfNull(): void
	{
		// IFNULL is MySQL-specific, should use COALESCE
		$stmt = $this->db->prepare(
			'SELECT IFNULL(name, "Unknown") FROM users WHERE id = :id',
		);
		$stmt->execute(['id' => 1]);
	}

	public function usingIf(): void
	{
		// IF() is MySQL-specific, should use CASE WHEN
		$stmt = $this->db->prepare(
			'SELECT IF(status = 1, "Active", "Inactive") FROM users',
		);
		$stmt->execute();
	}

	public function usingIfWithVariable(): void
	{
		// IF() in variable
		$sql = 'SELECT IF(age > 18, "Adult", "Minor") FROM users';
		$stmt = $this->db->prepare($sql);
		$stmt->execute();
	}

	public function usingCoalesce(): void
	{
		// COALESCE is portable - no error expected
		$stmt = $this->db->prepare(
			'SELECT COALESCE(name, "Unknown") FROM users WHERE id = :id',
		);
		$stmt->execute(['id' => 1]);
	}

	public function usingCaseWhen(): void
	{
		// CASE WHEN is portable - no error expected
		$stmt = $this->db->prepare(
			'SELECT CASE WHEN status = 1 THEN "Active" ELSE "Inactive" END FROM users',
		);
		$stmt->execute();
	}

	public function multipleIssues(): void
	{
		// Both IFNULL and IF in same query
		$stmt = $this->db->prepare(
			'SELECT IFNULL(name, "Unknown"), IF(active = 1, "Yes", "No") FROM users',
		);
		$stmt->execute();
	}

	public function usingQuery(): void
	{
		// Also works with query()
		$this->db->query('SELECT IFNULL(count, 0) FROM stats');
	}

	public function usingNow(): void
	{
		// NOW() is MySQL-specific, should use CURRENT_TIMESTAMP
		$stmt = $this->db->prepare('SELECT * FROM users WHERE created_at > NOW()');
		$stmt->execute();
	}

	public function usingCurdate(): void
	{
		// CURDATE() is MySQL-specific, should use CURRENT_DATE
		$stmt = $this->db->prepare(
			'SELECT * FROM users WHERE birth_date = CURDATE()',
		);
		$stmt->execute();
	}

	public function usingMySqlLimitSyntax(): void
	{
		// LIMIT offset, count is MySQL-specific
		$stmt = $this->db->prepare('SELECT * FROM users LIMIT 10, 5');
		$stmt->execute();
	}

	public function usingCurrentTimestamp(): void
	{
		// CURRENT_TIMESTAMP is portable - no error expected
		$stmt = $this->db->prepare(
			'SELECT * FROM users WHERE created_at > CURRENT_TIMESTAMP',
		);
		$stmt->execute();
	}

	public function usingCurrentDate(): void
	{
		// CURRENT_DATE is portable - no error expected
		$stmt = $this->db->prepare(
			'SELECT * FROM users WHERE birth_date = CURRENT_DATE',
		);
		$stmt->execute();
	}

	public function usingStandardLimitSyntax(): void
	{
		// LIMIT count OFFSET offset is portable - no error expected
		$stmt = $this->db->prepare('SELECT * FROM users LIMIT 5 OFFSET 10');
		$stmt->execute();
	}
}
