<?php

namespace Pierresh\PhpStanPdoMysql\Tests\Fixtures;

use PDO;

class SelfReferenceErrors
{
	private PDO $db;

	public function __construct(PDO $db)
	{
		$this->db = $db;
	}

	public function joinSelfReference(): void
	{
		// Error: JOIN condition references same table.column on both sides
		$stmt = $this->db->prepare('
            SELECT *
            FROM orders
            INNER JOIN products ON products.id = products.id
            WHERE id = 1
        ');
		$stmt->execute();
	}

	public function whereSelfReference(): void
	{
		// Error: WHERE condition references same column on both sides
		$stmt = $this->db->prepare('
            SELECT *
            FROM users
            WHERE users.id = users.id
        ');
		$stmt->execute();
	}

	public function whereWithAndSelfReference(): void
	{
		// Error: WHERE with AND containing self-reference
		$stmt = $this->db->prepare('
            SELECT *
            FROM users
            WHERE status = \'active\' AND users.id = users.id
        ');
		$stmt->execute();
	}

	public function multipleSelfReferences(): void
	{
		// Error: Multiple self-references in same query
		$stmt = $this->db->prepare('
            SELECT *
            FROM users
            INNER JOIN orders ON orders.id = orders.id
            WHERE users.id = users.id
        ');
		$stmt->execute();
	}

	public function leftJoinSelfReference(): void
	{
		// Error: LEFT JOIN with self-reference
		$stmt = $this->db->prepare('
            SELECT *
            FROM users
            LEFT JOIN orders ON orders.user_id = orders.user_id
        ');
		$stmt->execute();
	}

	public function validJoin(): void
	{
		// Valid: Different tables on left and right
		$stmt = $this->db->prepare('
            SELECT *
            FROM orders
            INNER JOIN products ON orders.product_id = products.id
            WHERE id = 1
        ');
		$stmt->execute();
	}

	public function validWhere(): void
	{
		// Valid: Different columns
		$stmt = $this->db->prepare('
            SELECT *
            FROM users
            WHERE users.id = orders.user_id
        ');
		$stmt->execute();
	}

	public function variableSqlWithSelfReference(): void
	{
		// Error: Self-reference in variable-based SQL
		$sql = '
            SELECT *
            FROM products
            WHERE products.category_id = products.category_id
        ';
		$stmt = $this->db->prepare($sql);
		$stmt->execute();
	}

	public function aliasSelfReferenceInJoin(): void
	{
		// Error: Self-reference using alias (alias.id = table.id)
		$stmt = $this->db->prepare('
            SELECT *
            FROM products AS p
            INNER JOIN orders ON p.id = products.id
        ');
		$stmt->execute();
	}

	public function aliasSelfReferenceInWhere(): void
	{
		// Error: Self-reference using alias in WHERE
		$stmt = $this->db->prepare('
            SELECT *
            FROM users u
            WHERE u.id = users.id
        ');
		$stmt->execute();
	}

	public function multipleAliasesSameTable(): void
	{
		// Valid: Different aliases for same table (self-join pattern)
		$stmt = $this->db->prepare('
            SELECT *
            FROM products p1
            INNER JOIN products p2 ON p1.id = p2.parent_id
        ');
		$stmt->execute();
	}

	public function aliasValidJoin(): void
	{
		// Valid: Alias for one table, joins with different table
		$stmt = $this->db->prepare('
            SELECT *
            FROM orders o
            INNER JOIN products p ON o.product_id = p.id
        ');
		$stmt->execute();
	}
}
