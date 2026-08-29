<?php

namespace Pierresh\PhpStanPdoMysql\Tests\Fixtures;

use PDO;

class PostgresSyntaxErrors
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
		// This should NOT report any error - RETURNING, ILIKE, double-quoted identifiers
		$stmt = $this->db->prepare('SELECT "id", "name" FROM "users" WHERE "name" ILIKE :name');
		$stmt->execute(['name' => '%a%']);
	}

	public function unterminatedQuotedIdentifier(): void
	{
		// Missing closing double quote
		$stmt = $this->db->prepare('SELECT "id, name FROM users');
	}

	public function digitLeadingPlaceholder(): void
	{
		// This should NOT report any error - PDO allows placeholder names starting with digits
		$stmt = $this->db->prepare('SELECT id FROM users WHERE created > :5min_ago');
		$stmt->execute(['5min_ago' => '2024-01-01']);
	}

	public function colonInStringLiteral(): void
	{
		// This should NOT report any error - colon in string literal is valid
		$stmt = $this->db->prepare(
			"INSERT INTO test (id, added_time) VALUES (1, '2:1')",
		);
	}
}
