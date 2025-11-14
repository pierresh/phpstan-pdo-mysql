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
		$stmt = $this->db->prepare(
			'SELECT * FROM users WHERE id = :id AND name = :name',
		);
		$stmt->execute(['id' => 1]); // Missing :name
	}

	public function extraParameter(): void
	{
		// SQL has :id, but execute provides :id and :extra
		$stmt = $this->db->prepare('SELECT * FROM users WHERE id = :id');
		$stmt->execute(['id' => 1, 'extra' => 'unused']); // Extra :extra
	}

	public function executeOverridesBindValue(): void
	{
		// bindValue is ignored when execute() has array params
		$stmt = $this->db->prepare('SELECT * FROM users WHERE id = :id');
		$stmt->bindValue(':id', 1); // This is ignored
		$stmt->execute(['name' => 'John']); // Missing :id, has wrong :name
	}

	public function validBinding(): void
	{
		// This should NOT report any error
		$stmt = $this->db->prepare(
			'SELECT * FROM users WHERE id = :id AND name = :name',
		);
		$stmt->execute(['id' => 1, 'name' => 'John']);
	}

	public function validBindValue(): void
	{
		// This should NOT report any error
		$stmt = $this->db->prepare('SELECT * FROM users WHERE id = :id');
		$stmt->bindValue(':id', 1);
		$stmt->execute();
	}

	public function variableSqlMismatch(): void
	{
		// SQL in variable with parameter mismatch
		$sql = 'SELECT * FROM users WHERE id = :user_id';
		$stmt = $this->db->prepare($sql);
		$stmt->execute(['id' => 1]); // Wrong parameter name
	}

	public function validBindingWithColonPrefix(): void
	{
		// This should NOT report any error - using : prefix in execute() array is valid
		$stmt = $this->db->prepare(
			'SELECT * FROM users WHERE id = :id AND name = :name',
		);
		$stmt->execute([':id' => 1, ':name' => 'John']);
	}

	public function missingParameterWithColonPrefix(): void
	{
		// SQL has :id and :name, but execute only provides :id (with : prefix)
		$stmt = $this->db->prepare(
			'SELECT * FROM users WHERE id = :id AND name = :name',
		);
		$stmt->execute([':id' => 1]); // Missing :name
	}

	public function extraParameterWithColonPrefix(): void
	{
		// SQL has :id, but execute provides :id and :extra (with : prefix)
		$stmt = $this->db->prepare('SELECT * FROM users WHERE id = :id');
		$stmt->execute([':id' => 1, ':extra' => 'unused']); // Extra :extra
	}

	public function mixedPrefixStyle(): void
	{
		// This should NOT report any error - mixing styles is valid
		$stmt = $this->db->prepare(
			'SELECT * FROM users WHERE id = :id AND name = :name',
		);
		$stmt->execute([':id' => 1, 'name' => 'John']); // Mixed: :id with prefix, name without
	}

	public function interpolatedStringMismatch(): void
	{
		// SQL in interpolated string with parameter mismatch
		$select = 'id, name';
		$stmt = $this->db->prepare("SELECT $select FROM users WHERE id = :user_id");
		$stmt->execute(['id' => 1]); // Wrong parameter name
	}

	public function extraParametersWithNoPlaceholders(): void
	{
		// SQL has NO placeholders, but execute provides parameters
		$stmt = $this->db->prepare('SELECT id, name FROM users');
		$stmt->execute(['id' => 1, 'name' => 'test']); // Extra parameters!
	}

	public function typoInBindValue(): void
	{
		// Typo in bindValue parameter name
		$stmt = $this->db->prepare('SELECT * FROM users WHERE id = :user_id');
		$stmt->bindValue(':user_i', 1); // Typo: should be :user_id
		$stmt->execute();
	}
}

class PropertyBindingErrors
{
	private PDO $db;

	private \PDOStatement $query;

	public function __construct(PDO $db)
	{
		$this->db = $db;

		// Prepare statement with :issue_code and :sp_code parameters
		$this->query = $this->db->prepare('
			INSERT INTO sp_issue_details (issue_id, sp_id)
			VALUES (
				(SELECT issue_id FROM sp_issue WHERE issue_code = :issue_code),
				(SELECT sp_id FROM sp_list WHERE sp_code = :sp_code)
			)
		');
	}

	public function handleIssueDetails(): void
	{
		// Typo in bindValue: :issu_code instead of :issue_code
		$this->query->bindValue(':issu_code', 'ABC123'); // Typo!
		$this->query->bindValue(':sp_code', 'SP001');
		$this->query->execute();
	}

	public function handleIssueDetailsInIf(): void
	{
		// execute() inside if statement should also be detected
		$this->query->bindValue(':issu_code', 'ABC123'); // Typo!
		$this->query->bindValue(':sp_code', 'SP001');

		if ($this->query->execute()) {
			echo 'Success';
		}
	}
}
