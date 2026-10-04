<?php

declare(strict_types=1);

namespace Bitrix\Crm\Activity\Email\Read;

use Bitrix\Crm\Activity\Email\EntityType;
use Bitrix\Crm\Activity\Provider\Email;
use Bitrix\Crm\ActivityBindingTable;
use Bitrix\Crm\ActivityTable;
use Bitrix\Crm\Result;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Entity\ReferenceField;
use Bitrix\Main\ORM\Query\Query;
use CCrmOwnerType;

final class SearchProvider
{
	public const DEFAULT_LIMIT = 25;
	public const MAX_LIMIT = 100;

	public function search(
		int $userId,
		int $entityTypeId,
		int $entityId,
		?int $direction = null,
		?int $limit = null,
		?int $offset = null,
		bool $countTotal = true,
	): Result
	{
		$limit = max(1, min(self::MAX_LIMIT, (int)($limit ?? self::DEFAULT_LIMIT)));
		$offset = max(0, (int)($offset ?? 0));

		if (!CCrmOwnerType::IsDefined($entityTypeId))
		{
			return Result::fail('Unknown entityTypeId.');
		}
		if (!EntityType::isSupported($entityTypeId))
		{
			return Result::fail('Unsupported entityTypeId.');
		}

		$userPerms = Container::getInstance()->getUserPermissions($userId)->getCrmPermissions();
		if (!\CCrmActivity::CheckReadPermission($entityTypeId, $entityId, $userPerms))
		{
			return Result::fail('Access denied to the entity.');
		}

		$query = $this->buildQuery($entityTypeId, $entityId, $direction)
			->setSelect([
				'EMAIL_ACTIVITY_ID' => 'ACTIVITY.ID',
				'SUBJECT' => 'ACTIVITY.SUBJECT',
				'START_TIME' => 'ACTIVITY.START_TIME',
				'CREATED' => 'ACTIVITY.CREATED',
				'LAST_UPDATED' => 'ACTIVITY.LAST_UPDATED',
				'DEADLINE' => 'ACTIVITY.DEADLINE',
				'DIRECTION' => 'ACTIVITY.DIRECTION',
				'ACTIVITY_OWNER_TYPE_ID' => 'ACTIVITY.OWNER_TYPE_ID',
				'ACTIVITY_OWNER_ID' => 'ACTIVITY.OWNER_ID',
				'PROVIDER_ID' => 'ACTIVITY.PROVIDER_ID',
				'PROVIDER_TYPE_ID' => 'ACTIVITY.PROVIDER_TYPE_ID',
				'ASSOCIATED_ENTITY_ID' => 'ACTIVITY.ASSOCIATED_ENTITY_ID',
				'PARENT_ID' => 'ACTIVITY.PARENT_ID',
				'THREAD_ID' => 'ACTIVITY.THREAD_ID',
				'AUTHOR_ID' => 'ACTIVITY.AUTHOR_ID',
				'EDITOR_ID' => 'ACTIVITY.EDITOR_ID',
				'RESPONSIBLE_ID' => 'ACTIVITY.RESPONSIBLE_ID',
				'SETTINGS' => 'ACTIVITY.SETTINGS',
			])
			->setOrder(['ACTIVITY.START_TIME' => 'DESC', 'ACTIVITY.ID' => 'DESC'])
			->setLimit($countTotal ? $limit : $limit + 1)
			->setOffset($offset)
		;

		$result = $query->exec();
		$activities = [];
		while ($row = $result->fetch())
		{
			$activities[] = EmailActivity::fromActivityRow($this->mapActivityRow($row));
		}

		if (!$countTotal)
		{
			$hasMore = count($activities) > $limit;
			if ($hasMore)
			{
				array_pop($activities);
			}

			$returnedCount = count($activities);

			return Result::success(
				activities: $activities,
				returnedCount: $returnedCount,
				hasMore: $hasMore,
				nextOffset: $hasMore ? $offset + $returnedCount : null,
				limit: $limit,
				offset: $offset,
			);
		}

		$totalCount = (int)$this
			->buildQuery($entityTypeId, $entityId, $direction)
			->addSelect('ACTIVITY_ID')
			->queryCountTotal()
		;
		$returnedCount = count($activities);
		$remainingCount = max(0, $totalCount - ($offset + $returnedCount));

		return Result::success(
			activities: $activities,
			totalCount: $totalCount,
			returnedCount: $returnedCount,
			remainingCount: $remainingCount,
			hasMore: $remainingCount > 0,
			nextOffset: $remainingCount > 0 ? $offset + $returnedCount : null,
			limit: $limit,
			offset: $offset,
		);
	}

	private function buildQuery(int $entityTypeId, int $entityId, ?int $direction): Query
	{
		$query = ActivityBindingTable::query()
			->registerRuntimeField(
				'',
				new ReferenceField(
					'ACTIVITY',
					ActivityTable::getEntity(),
					['=this.ACTIVITY_ID' => 'ref.ID'],
					['join_type' => 'INNER'],
				),
			)
			->where('OWNER_TYPE_ID', $entityTypeId)
			->where('OWNER_ID', $entityId)
			->where('ACTIVITY.PROVIDER_ID', Email::getId())
		;

		if ($direction !== null)
		{
			$query->where('ACTIVITY.DIRECTION', $direction);
		}

		return $query;
	}

	private function mapActivityRow(array $row): array
	{
		$row['ID'] = $row['EMAIL_ACTIVITY_ID'];
		$row['OWNER_TYPE_ID'] = $row['ACTIVITY_OWNER_TYPE_ID'];
		$row['OWNER_ID'] = $row['ACTIVITY_OWNER_ID'];

		unset($row['EMAIL_ACTIVITY_ID'], $row['ACTIVITY_OWNER_TYPE_ID'], $row['ACTIVITY_OWNER_ID']);

		return $row;
	}
}
