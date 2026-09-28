<?php

namespace Pierresh\PhpStanPdoMysql\Tests\Fixtures;

use PDO;
use PDOStatement;

class SelectColumnLoopErrors
{
	private PDOStatement $users;

	public function __construct(private PDO $db)
	{
		$this->users = $db->prepare('SELECT id, name, email FROM users WHERE active = 1');
	}

	public function whileFetchOnPropertyNoMismatch(): void
	{
		// The loop row comes from $this->users (prepared in the constructor),
		// not from $orders, even though $orders is the closest prepare() above.
		$orders = $this->db->prepare('SELECT order_id, total FROM orders WHERE user_id = :user_id');
		$this->users->execute();

		while ($user = $this->users->fetch()) {
			/** @var object{id: int, name: string, email: string} $user */
			$orders->execute(['user_id' => $user->id]);
		}
	}

	public function whileFetchOnPropertyStillValidatesColumns(): void
	{
		$orders = $this->db->prepare('SELECT order_id, total FROM orders WHERE user_id = :user_id');
		$this->users->execute();

		while ($user = $this->users->fetch()) {
			/** @var object{id: int, name: string, phone: string} $user */
			$orders->execute(['user_id' => $user->id]);
		}
	}

	public function fetchInsideWhileLoopUsesItsOwnStatement(): void
	{
		$orders = $this->db->prepare('SELECT order_id, total FROM orders WHERE user_id = :user_id');
		$this->users->execute();

		while ($user = $this->users->fetch()) {
			/** @var object{id: int} $user */
			$orders->execute(['user_id' => $user->id]);

			// This @var belongs to $orders, not to the loop's $this->users
			/** @var object{order_id: int, total: float, status: string} $order */
			$order = $orders->fetch();
		}
	}

	public function continueOnEmptyRowCountInLoopNoMissingFalse(): void
	{
		$orders = $this->db->prepare('SELECT order_id, total FROM orders WHERE user_id = :user_id');
		$this->users->execute();

		while ($user = $this->users->fetch()) {
			/** @var object{id: int} $user */
			$orders->execute(['user_id' => $user->id]);

			if ($orders->rowCount() === 0) {
				continue;
			}

			/** @var object{order_id: int, total: float} $order */
			$order = $orders->fetch();
		}
	}

	/** @param list<int> $userIds */
	public function breakOnEmptyRowCountInForeachNoMissingFalse(array $userIds): void
	{
		$orders = $this->db->prepare('SELECT order_id, total FROM orders WHERE user_id = :user_id');

		foreach ($userIds as $userId) {
			$orders->execute(['user_id' => $userId]);

			if ($orders->rowCount() === 0) {
				break;
			}

			/** @var object{order_id: int, total: float} $order */
			$order = $orders->fetch();
		}
	}

	public function executeFailureOrEmptyRowCountGuardNoMissingFalse(): void
	{
		$orders = $this->db->prepare('SELECT order_id, total FROM orders WHERE user_id = :user_id');

		if ($orders->execute(['user_id' => 1]) === false || $orders->rowCount() === 0) {
			return;
		}

		/** @var object{order_id: int, total: float} $order */
		$order = $orders->fetch();
	}

	public function rowCountGuardOnOtherStatementStillFlagged(): void
	{
		$this->users->execute();

		if ($this->users->rowCount() === 0) {
			return;
		}

		$orders = $this->db->prepare('SELECT order_id, total FROM orders WHERE user_id = :user_id');
		$orders->execute(['user_id' => 1]);

		// The guard above is about $this->users, it says nothing about $orders
		/** @var object{order_id: int, total: float} $order */
		$order = $orders->fetch();
	}

	public function rowCountGuardInLoopOnOtherStatementStillFlagged(): void
	{
		$orders = $this->db->prepare('SELECT order_id, total FROM orders WHERE user_id = :user_id');
		$this->users->execute();

		while ($user = $this->users->fetch()) {
			/** @var object{id: int} $user */
			$orders->execute(['user_id' => $user->id]);

			if ($this->users->rowCount() === 0) {
				continue;
			}

			/** @var object{order_id: int, total: float} $order */
			$order = $orders->fetch();
		}
	}

	public function rowCountGuardAfterFetchStillFlagged(): void
	{
		$orders = $this->db->prepare('SELECT order_id, total FROM orders WHERE user_id = :user_id');
		$orders->execute(['user_id' => 1]);

		/** @var object{order_id: int, total: float} $order */
		$order = $orders->fetch();

		if ($orders->rowCount() === 0) {
			return;
		}
	}

	public function rowCountGuardInSiblingBranchStillFlagged(bool $flag): void
	{
		$orders = $this->db->prepare('SELECT order_id, total FROM orders WHERE user_id = :user_id');
		$orders->execute(['user_id' => 1]);

		if ($flag) {
			if ($orders->rowCount() === 0) {
				return;
			}
		} else {
			// The guard is in the other branch, it does not protect this fetch
			/** @var object{order_id: int, total: float} $order */
			$order = $orders->fetch();
		}
	}

	public function nestedWhileFetchUsesInnerStatement(): void
	{
		$orders = $this->db->prepare('SELECT order_id, total FROM orders WHERE user_id = :user_id');
		$this->users->execute();

		while ($user = $this->users->fetch()) {
			/** @var object{id: int} $user */
			$orders->execute(['user_id' => $user->id]);

			while ($order = $orders->fetch()) {
				/** @var object{order_id: int, total: float, status: string} $order */
				echo $order->total;
			}
		}
	}

	public function rowCountEqualsOneGuardNoMissingFalse(): void
	{
		$orders = $this->db->prepare('SELECT order_id, total FROM orders WHERE user_id = :user_id');
		$orders->execute(['user_id' => 1]);

		if ($orders->rowCount() === 1) {
			/** @var object{order_id: int, total: float} $order */
			$order = $orders->fetch();
		}
	}
}
