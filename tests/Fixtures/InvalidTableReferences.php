<?php

declare(strict_types=1);

namespace Pierresh\PhpStanPdoMysql\Tests\Fixtures;

use PDO;

class InvalidTableReferences
{
	private PDO $db;

	public function __construct(PDO $db)
	{
		$this->db = $db;
	}

	// ❌ Table 'user' doesn't exist - should be 'users'
	public function wrongTableNameInSelect(): void
	{
		$stmt = $this->db->prepare('
			SELECT users.id, user.name, users.email
			FROM users
			WHERE users.id = :id
		');
		$stmt->execute(['id' => 1]);
	}

	// ❌ Wrong alias - using 'usr' but alias is 'u'
	public function wrongAliasReference(): void
	{
		$stmt = $this->db->prepare('
			SELECT u.id, usr.name, u.email
			FROM users AS u
			WHERE u.id = :id
		');
		$stmt->execute(['id' => 1]);
	}

	// ❌ Table 'orders' referenced but not in FROM or JOIN
	public function tableNotInFromClause(): void
	{
		$stmt = $this->db->prepare('
			SELECT users.id, orders.order_id
			FROM users
			WHERE users.id = :id
		');
		$stmt->execute(['id' => 1]);
	}

	// ❌ Wrong alias in JOIN condition
	public function wrongAliasInJoin(): void
	{
		$stmt = $this->db->prepare('
			SELECT u.id, o.order_id
			FROM users AS u
			INNER JOIN orders AS o ON usr.id = o.user_id
			WHERE u.id = :id
		');
		$stmt->execute(['id' => 1]);
	}

	// ❌ Multiple errors - mixed wrong table and wrong alias
	public function multipleErrors(): void
	{
		$stmt = $this->db->prepare('
			SELECT user.id, ord.total
			FROM users AS u
			INNER JOIN orders AS o ON u.id = o.user_id
			WHERE u.id = :id
		');
		$stmt->execute(['id' => 1]);
	}

	// ✅ Valid - correct table name
	public function validTableName(): void
	{
		$stmt = $this->db->prepare('
			SELECT users.id, users.name
			FROM users
			WHERE users.id = :id
		');
		$stmt->execute(['id' => 1]);
	}

	// ✅ Valid - correct alias usage
	public function validAliasUsage(): void
	{
		$stmt = $this->db->prepare('
			SELECT u.id, u.name, u.email
			FROM users AS u
			WHERE u.id = :id
		');
		$stmt->execute(['id' => 1]);
	}

	// ✅ Valid - multiple tables with aliases
	public function validMultipleTables(): void
	{
		$stmt = $this->db->prepare('
			SELECT u.id, u.name, o.order_id, o.total
			FROM users AS u
			INNER JOIN orders AS o ON u.id = o.user_id
			WHERE u.id = :id
		');
		$stmt->execute(['id' => 1]);
	}

	// ✅ Valid - unqualified column names (no table prefix)
	public function validUnqualifiedColumns(): void
	{
		$stmt = $this->db->prepare('
			SELECT id, name, email
			FROM users
			WHERE id = :id
		');
		$stmt->execute(['id' => 1]);
	}

	// ✅ Valid - table name used even when alias exists
	public function validTableNameWithAlias(): void
	{
		$stmt = $this->db->prepare('
			SELECT users.id, users.name
			FROM users AS u
			WHERE users.id = :id
		');
		$stmt->execute(['id' => 1]);
	}
}
