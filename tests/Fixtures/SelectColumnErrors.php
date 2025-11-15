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
		$stmt = $this->db->prepare('SELECT id, nam, email FROM users WHERE id = :id');
		$stmt->execute(['id' => 1]);

		/** @var object{id: int, name: string, email: string} */
		$user = $stmt->fetch();
	}

	public function missingColumn(): void
	{
		$stmt = $this->db->prepare('SELECT id, name FROM users WHERE id = :id');
		$stmt->execute(['id' => 1]);

		/** @var object{id: int, name: string, email: string} */
		$user = $stmt->fetch();
	}

	public function extraColumn(): void
	{
		$stmt = $this->db->prepare(
			'SELECT id, name, email, created_at FROM users WHERE id = :id',
		);
		$stmt->execute(['id' => 1]);

		/** @var object{id: int, name: string, email: string} */
		$user = $stmt->fetch();
	}

	public function typeAliasError(): void
	{
		$stmt = $this->db->prepare('SELECT id, nam FROM users WHERE id = :id');
		$stmt->execute(['id' => 1]);

		/** @var User */
		$user = $stmt->fetch();
	}

	public function validColumns(): void
	{
		$stmt = $this->db->prepare(
			'SELECT id, name, email FROM users WHERE id = :id',
		);
		$stmt->execute(['id' => 1]);

		/** @var object{id: int, name: string, email: string} */
		$user = $stmt->fetch();
	}

	public function validWithTypeAlias(): void
	{
		$stmt = $this->db->prepare(
			'SELECT id, name, email FROM users WHERE id = :id',
		);
		$stmt->execute(['id' => 1]);

		/** @var User */
		$user = $stmt->fetch();
	}

	public function variableSqlColumnError(): void
	{
		$sql = 'SELECT id, nam FROM users WHERE id = :id';
		$stmt = $this->db->prepare($sql);
		$stmt->execute(['id' => 1]);

		/** @var object{id: int, name: string} */
		$user = $stmt->fetch();
	}

	public function multipleQueriesVariableBasedMatching(): void
	{
		// First query: only has 2 columns (id, name)
		$stmt = $this->db->prepare('SELECT id, name FROM users WHERE id = :id');

		// Second query: has all 3 columns (id, name, email)
		$stmt3 = $this->db->prepare(
			'SELECT id, name, email FROM users WHERE id = :id',
		);
		$stmt3->execute(['id' => 1]);

		// This @var expects 3 columns but uses $stmt (which only has 2 columns)
		// Variable-based matching should detect this mismatch
		/** @var object{id: int, name: string, email: string} */
		$user = $stmt->fetch();
	}

	public function multipleQueriesVariableBasedMatchingCorrect(): void
	{
		// First query: only has 2 columns (id, name)
		$stmt = $this->db->prepare('SELECT id, name FROM users WHERE id = :id');

		// Second query: has all 3 columns (id, name, email)
		$stmt3 = $this->db->prepare(
			'SELECT id, name, email FROM users WHERE id = :id',
		);
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
		$stmt = $this->db->prepare('SELECT * FROM users WHERE id = :id');
		$stmt->execute(['id' => 1]);

		/** @var object{id: int, name: string, email: string} */
		$user = $stmt->fetch();
	}

	public function selectTableStarShouldNotError(): void
	{
		// SELECT table.* cannot be validated statically, so it should be silently skipped
		// This should NOT produce an error even with single-line PHPDoc
		$stmt = $this->db->prepare(
			'SELECT users.*, other_col FROM users WHERE id = :id',
		);
		$stmt->execute(['id' => 1]);

		/** @var object{id: int, name: string, email: string} */
		$user = $stmt->fetch();
	}

	public function fetchAllWrongType(): void
	{
		// fetchAll() returns array of objects, not a single object
		// This should ERROR: PHPDoc should be array<object{...}> not object{...}
		$stmt = $this->db->prepare('SELECT id, name FROM users');
		$stmt->execute();

		/** @var object{id: int, name: string} */
		$users = $stmt->fetchAll();
	}

	public function fetchAllCorrectType(): void
	{
		// fetchAll() with correct array type - should NOT error
		$stmt = $this->db->prepare('SELECT id, name FROM users');
		$stmt->execute();

		/** @var array<object{id: int, name: string}> */
		$users = $stmt->fetchAll();
	}

	public function fetchWrongType(): void
	{
		// fetch() returns a single object, not an array
		// This should ERROR: PHPDoc should be object{...} not array<object{...}>
		$stmt = $this->db->prepare('SELECT id, name FROM users');
		$stmt->execute();

		/** @var array<object{id: int, name: string}> */
		$user = $stmt->fetch();
	}

	public function fetchAllWrongTypeWithSuffixSyntax(): void
	{
		// fetchAll() returns array of objects, not a single object
		// Using [] suffix syntax instead of array<>
		// This should ERROR: PHPDoc should be object{...}[] not object{...}
		$stmt = $this->db->prepare('SELECT id, name FROM users');
		$stmt->execute();

		/** @var object{id: int, name: string} */
		$users = $stmt->fetchAll();
	}

	public function fetchAllCorrectTypeWithSuffixSyntax(): void
	{
		// fetchAll() with correct array type using [] suffix - should NOT error
		$stmt = $this->db->prepare('SELECT id, name FROM users');
		$stmt->execute();

		/** @var object{id: int, name: string}[] */
		$users = $stmt->fetchAll();
	}

	public function fetchWrongTypeWithSuffixSyntax(): void
	{
		// fetch() returns a single object, not an array
		// This should ERROR: PHPDoc should be object{...} not object{...}[]
		$stmt = $this->db->prepare('SELECT id, name FROM users');
		$stmt->execute();

		/** @var object{id: int, name: string}[] */
		$user = $stmt->fetch();
	}

	public function fetchWithoutFalseType(): void
	{
		// fetch() can return false, but @var doesn't include |false
		// No false-handling code present
		// This should ERROR: Missing |false in type
		$stmt = $this->db->prepare('SELECT id, name FROM users WHERE id = :id');
		$stmt->execute(['id' => 1]);

		/** @var object{id: int, name: string} */
		$user = $stmt->fetch();
	}

	public function fetchWithFalseType(): void
	{
		// fetch() with |false in type - should NOT error
		$stmt = $this->db->prepare('SELECT id, name FROM users WHERE id = :id');
		$stmt->execute(['id' => 1]);

		/** @var object{id: int, name: string}|false */
		$user = $stmt->fetch();
	}

	public function fetchWithFalseTypeWithSpace(): void
	{
		// fetch() with | false (space before false) - should NOT error
		$stmt = $this->db->prepare('SELECT id, name FROM users WHERE id = :id');
		$stmt->execute(['id' => 1]);

		/** @var object{id: int, name: string} | false */
		$user = $stmt->fetch();
	}

	public function fetchWithFalseTypeReverseOrder(): void
	{
		// fetch() with false|object (reverse order) - should NOT error
		$stmt = $this->db->prepare('SELECT id, name FROM users WHERE id = :id');
		$stmt->execute(['id' => 1]);

		/** @var false|object{id: int, name: string} */
		$user = $stmt->fetch();
	}

	public function fetchWithRowCountCheck(): void
	{
		// fetch() without |false but has rowCount() check - should NOT error
		$stmt = $this->db->prepare('SELECT id, name FROM users WHERE id = :id');
		$stmt->execute(['id' => 1]);

		if ($stmt->rowCount() === 0) {
			throw new \RuntimeException('User not found');
		}

		/** @var object{id: int, name: string} */
		$user = $stmt->fetch();
	}

	public function fetchWithExplicitFalseCheck(): void
	{
		// fetch() without |false but checks === false - should NOT error
		$stmt = $this->db->prepare('SELECT id, name FROM users WHERE id = :id');
		$stmt->execute(['id' => 1]);

		/** @var object{id: int, name: string} */
		$user = $stmt->fetch();

		if ($user === false) {
			throw new \RuntimeException('User not found');
		}
	}

	public function fetchObjectWithoutFalseType(): void
	{
		// fetchObject() can return false, but @var doesn't include |false
		// No false-handling code present
		// This should ERROR: Missing |false in type
		$stmt = $this->db->prepare('SELECT id, name FROM users WHERE id = :id');
		$stmt->execute(['id' => 1]);

		/** @var object{id: int, name: string} */
		$user = $stmt->fetchObject();
	}

	public function fetchAllShouldNotRequireFalse(): void
	{
		// fetchAll() returns array, NOT false
		// Should NOT error even without |false
		$stmt = $this->db->prepare('SELECT id, name FROM users');
		$stmt->execute();

		/** @var array<object{id: int, name: string}> */
		$users = $stmt->fetchAll();
	}

	public function fetchWithNotFalseCheck(): void
	{
		// fetch() without |false but checks !== false - should NOT error
		$stmt = $this->db->prepare('SELECT id, name FROM users WHERE id = :id');
		$stmt->execute(['id' => 1]);

		/** @var object{id: int, name: string} */
		$user = $stmt->fetch();

		if ($user !== false) {
			// Use $user
		}
	}

	public function fetchWithNegationCheck(): void
	{
		// fetch() without |false but checks !$user - should NOT error
		$stmt = $this->db->prepare('SELECT id, name FROM users WHERE id = :id');
		$stmt->execute(['id' => 1]);

		/** @var object{id: int, name: string} */
		$user = $stmt->fetch();

		if (!$user) {
			throw new \RuntimeException('User not found');
		}
	}

	public function fetchWithRowCountCheckNoThrow(): void
	{
		// fetch() without |false and rowCount() check without throw/return
		// This should ERROR because rowCount check doesn't prevent execution
		$stmt = $this->db->prepare('SELECT id, name FROM users WHERE id = :id');
		$stmt->execute(['id' => 1]);

		if ($stmt->rowCount() === 0) {
			// Empty - doesn't throw or return
		}

		/** @var object{id: int, name: string} */
		$user = $stmt->fetch();
	}
}
