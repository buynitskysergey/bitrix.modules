<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Repository\User;

use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;
use Bitrix\Vibecodeconnector\Internal\Entity\User\UserAttribute;
use Bitrix\Vibecodeconnector\Internal\Model\User\UserAttributesTable;

final class UserAttributesRepository
{
	private const GROUP_EVENT_SEQUENCE_LOCK_PREFIX = 'vibecodeconnector:user-group-event-sequence:';
	private const GROUP_EVENT_SEQUENCE_LOCK_TIMEOUT = 5;

	public function get(int $userId, UserAttribute $attr): mixed
	{
		$row = UserAttributesTable::query()
			->setSelect([$attr->value])
			->where('USER_ID', $userId)
			->setLimit(1)
			->fetch();

		if ($row === false)
		{
			return null;
		}

		return $row[$attr->value] ?? null;
	}

	/**
	 * @param list<int> $userIds
	 * @return array<int, mixed>
	 */
	public function getMany(array $userIds, UserAttribute $attr): array
	{
		if ($userIds === [])
		{
			return [];
		}

		$rows = UserAttributesTable::query()
			->setSelect(['USER_ID', $attr->value])
			->whereIn('USER_ID', $userIds)
			->fetchAll();

		$values = [];
		foreach ($rows as $row)
		{
			$values[(int)$row['USER_ID']] = $row[$attr->value] ?? null;
		}

		return $values;
	}

	public function set(int $userId, UserAttribute $attr, mixed $value): void
	{
		$now = new DateTime();
		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();
		$tableName = UserAttributesTable::getTableName();

		[$mergeSql] = $helper->prepareMerge(
			$tableName,
			['USER_ID'],
			['USER_ID' => $userId, 'CREATED_AT' => $now, $attr->value => $value],
			[$attr->value => $value],
		);

		if ($mergeSql !== '')
		{
			$connection->queryExecute($mergeSql);

			return;
		}

		$existing = $this->findId($userId);
		if ($existing !== null)
		{
			UserAttributesTable::update($existing, [$attr->value => $value]);

			return;
		}

		try
		{
			UserAttributesTable::add([
				'USER_ID' => $userId,
				'CREATED_AT' => $now,
				$attr->value => $value,
			]);
		}
		catch (\Throwable $e)
		{
			$existing = $this->findId($userId);
			if ($existing === null)
			{
				throw $e;
			}
			UserAttributesTable::update($existing, [$attr->value => $value]);
		}
	}

	public function incrementGroupEventSequence(int $userId): int
	{
		$connection = Application::getConnection();
		$lockName = self::GROUP_EVENT_SEQUENCE_LOCK_PREFIX . $userId;
		if (!$connection->lock($lockName, self::GROUP_EVENT_SEQUENCE_LOCK_TIMEOUT))
		{
			throw new \RuntimeException('Unable to acquire the user group event sequence lock');
		}

		try
		{
			$currentSequence = (int)($this->get($userId, UserAttribute::GroupEventSequence) ?? 0);
			$nextSequence = $currentSequence + 1;
			$this->set($userId, UserAttribute::GroupEventSequence, $nextSequence);

			return $nextSequence;
		}
		finally
		{
			$connection->unlock($lockName);
		}
	}

	public function exists(int $userId, UserAttribute $attr): bool
	{
		return $this->get($userId, $attr) !== null;
	}

	public function delete(int $userId, UserAttribute $attr): void
	{
		$existing = $this->findId($userId);
		if ($existing === null)
		{
			return;
		}

		UserAttributesTable::update($existing, [$attr->value => null]);
	}

	private function findId(int $userId): ?int
	{
		$row = UserAttributesTable::query()
			->setSelect(['ID'])
			->where('USER_ID', $userId)
			->setLimit(1)
			->fetch();

		return $row !== false ? (int)$row['ID'] : null;
	}
}
