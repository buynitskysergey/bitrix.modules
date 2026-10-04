<?php

declare(strict_types=1);

namespace Bitrix\Crm\Activity\Email;

use Bitrix\Crm\ActivityBindingTable;
use Bitrix\Crm\Service\Container;

final class Access
{
	/**
	 * @return list<array{OWNER_TYPE_ID:int, OWNER_ID:int}>
	 */
	public function fetchBindings(int $activityId): array
	{
		$result =
			ActivityBindingTable::query()
				->setSelect(['OWNER_TYPE_ID', 'OWNER_ID'])
				->where('ACTIVITY_ID', $activityId)
				->exec()
		;

		$bindings = [];
		while ($row = $result->fetch())
		{
			$bindings[] = [
				'OWNER_TYPE_ID' => (int)$row['OWNER_TYPE_ID'],
				'OWNER_ID' => (int)$row['OWNER_ID'],
			];
		}

		return $bindings;
	}

	/**
	 * @param list<int> $activityIds
	 * @return array<int, list<array{OWNER_TYPE_ID:int, OWNER_ID:int}>>
	 */
	public function fetchBindingsByActivityId(array $activityIds): array
	{
		$activityIds = array_values(array_unique(array_filter($activityIds)));
		if (empty($activityIds))
		{
			return [];
		}

		$result =
			ActivityBindingTable::query()
				->setSelect(['ACTIVITY_ID', 'OWNER_TYPE_ID', 'OWNER_ID'])
				->whereIn('ACTIVITY_ID', $activityIds)
				->exec()
		;

		$bindings = [];
		while ($row = $result->fetch())
		{
			$activityId = (int)$row['ACTIVITY_ID'];
			$bindings[$activityId][] = [
				'OWNER_TYPE_ID' => (int)$row['OWNER_TYPE_ID'],
				'OWNER_ID' => (int)$row['OWNER_ID'],
			];
		}

		return $bindings;
	}

	/**
	 * @param list<array{OWNER_TYPE_ID:int, OWNER_ID:int}>|null $bindings
	 */
	public function canRead(array $activity, int $userId, ?array $bindings = null, bool $checkOwnerFirst = true): bool
	{
		$userPerms = Container::getInstance()->getUserPermissions($userId)->getCrmPermissions();
		if (
			$checkOwnerFirst
			&& \CCrmActivity::CheckReadPermission((int)$activity['OWNER_TYPE_ID'], (int)$activity['OWNER_ID'], $userPerms)
		)
		{
			return true;
		}

		if ($bindings === null)
		{
			$bindings = $this->fetchBindings((int)$activity['ID']);
		}

		if (empty($bindings) && !$checkOwnerFirst)
		{
			$bindings = [
				[
					'OWNER_TYPE_ID' => (int)$activity['OWNER_TYPE_ID'],
					'OWNER_ID' => (int)$activity['OWNER_ID'],
				],
			];
		}

		foreach ($bindings as $binding)
		{
			if (\CCrmActivity::CheckReadPermission((int)$binding['OWNER_TYPE_ID'], (int)$binding['OWNER_ID'], $userPerms))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @param list<array{OWNER_TYPE_ID:int, OWNER_ID:int}> $bindings
	 * @return list<array{entityTypeId:int, entityId:int}>
	 */
	public function formatBindings(array $bindings): array
	{
		$result = [];
		foreach ($bindings as $binding)
		{
			$result[] = [
				'entityTypeId' => (int)$binding['OWNER_TYPE_ID'],
				'entityId' => (int)$binding['OWNER_ID'],
			];
		}

		return $result;
	}
}
