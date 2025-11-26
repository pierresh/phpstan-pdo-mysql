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
		$stmt = $this->db->query('SELECT * FROM');
	}

	public function validSql(): void
	{
		// This should NOT report any error
		$stmt = $this->db->prepare('SELECT id, name FROM users WHERE id = :id');
		$stmt->execute(['id' => 1]);
	}

	public function trailingCommaInValues(): void
	{
		// Trailing comma in VALUES list
		$stmt = $this->db->prepare("
            INSERT INTO users (id, name, email)
            VALUES (1, 'John', 'john@example.com',)
        ");
	}

	public function multiLineErrorOnLine2(): void
	{
		// Error on the second line of SQL - trailing comma after "name"
		$stmt = $this->db->prepare('SELECT id, name,
            FROM users
            WHERE id = 1');
	}

	public function multiLineErrorOnLine3(): void
	{
		// Error on the third line of SQL
		$stmt = $this->db->prepare('SELECT id, name
            FROM users
            WHERE'); // Incomplete WHERE clause
	}

	public function colonInStringLiteral(): void
	{
		// This should NOT report any error - colon in string literal is valid
		$stmt = $this->db->prepare(
			"INSERT INTO test (id, added_time) VALUES (1, '2:1')",
		);
	}

	public function colonInDoubleQuotedString(): void
	{
		// This should NOT report any error - colon in double-quoted string is valid
		$stmt = $this->db->prepare(
			'INSERT INTO test (id, time_format) VALUES (1, "12:30:45")',
		);
	}
}
