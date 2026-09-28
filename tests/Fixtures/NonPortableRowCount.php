<?php

namespace Pierresh\PhpStanPdoMysql\Tests\Fixtures;

use PDO;
use PDOStatement;

class NonPortableRowCount
{
	private PDOStatement $users;

	private PDOStatement $disableUser;

	public function __construct(private PDO $db)
	{
		$this->users = $db->prepare('SELECT id FROM users WHERE active = 1');
		$this->disableUser = $db->prepare('UPDATE users SET active = 0 WHERE id = :id');
	}

	public function greaterThanZeroOnSelect(): bool
	{
		$stmt = $this->db->prepare('SELECT id FROM users WHERE id = :id');
		$stmt->execute(['id' => 1]);

		return $stmt->rowCount() > 0;
	}

	public function equalsOneOnPropertySelect(): bool
	{
		$this->users->execute();

		return $this->users->rowCount() === 1;
	}

	public function lessThanOneOnSelectFromSqlVariable(): void
	{
		$sql = 'SELECT id FROM users WHERE id = :id';
		$stmt = $this->db->prepare($sql);
		$stmt->execute(['id' => 1]);

		if ($stmt->rowCount() < 1) {
			return;
		}
	}

	public function zeroOnLeftOnQuery(): bool
	{
		$stmt = $this->db->query('SELECT id FROM users');

		return 0 < $stmt->rowCount();
	}

	public function greaterThanZeroOnWithSelect(): bool
	{
		$stmt = $this->db->prepare('
			WITH active_users AS (SELECT id FROM users WHERE active = 1)
			SELECT id FROM active_users
		');
		$stmt->execute();

		return $stmt->rowCount() >= 1;
	}

	public function portableChecksOnSelect(): bool
	{
		$stmt = $this->db->prepare('SELECT id FROM users WHERE id = :id');
		$stmt->execute(['id' => 1]);

		if ($stmt->rowCount() === 0 || $stmt->rowCount() == 0 || 0 === $stmt->rowCount()) {
			return false;
		}

		if (!$stmt->rowCount()) {
			return false;
		}

		return $stmt->rowCount() !== 0 && $stmt->rowCount() != 0;
	}

	public function greaterThanZeroOnUpdateIsPortable(): bool
	{
		$this->disableUser->execute(['id' => 1]);

		return $this->disableUser->rowCount() > 0;
	}

	public function greaterThanZeroOnDeleteIsPortable(): bool
	{
		$stmt = $this->db->prepare('DELETE FROM users WHERE id = :id');
		$stmt->execute(['id' => 1]);

		return $stmt->rowCount() > 0;
	}

	public function unknownStatementNotFlagged(PDOStatement $stmt): bool
	{
		return $stmt->rowCount() > 0;
	}

	public function reassignedStatementUsesLatestSql(): bool
	{
		$stmt = $this->db->prepare('SELECT id FROM users WHERE id = :id');
		$stmt->execute(['id' => 1]);
		$found = $stmt->rowCount() !== 0;

		$stmt = $this->db->prepare('UPDATE users SET active = 1 WHERE id = :id');
		$stmt->execute(['id' => 1]);

		return $found && $stmt->rowCount() > 0;
	}
}
