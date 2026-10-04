<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Loader;

use Bitrix\Crm\Model\LastCommunicationTable;
use Bitrix\Crm\V2\Public\Entity\Item\Item;
use Bitrix\Crm\V2\Public\Entity\Item\LastCommunication;
use Bitrix\Crm\V2\Public\Provider\Item\Param\ItemSelect;

class LastCommunicationLoader
{
	private const BATCH_SIZE = 500;

	/**
	 * @param Item[] $items
	 */
	public function load(array $items, ItemSelect $select): void
	{
		if (!$select->shouldLoadLastCommunication())
		{
			return;
		}

		$persisted = array_values(array_filter($items, static fn(Item $item) => $item->getId() !== null));
		if ($persisted === [])
		{
			return;
		}

		$entityTypeId = $persisted[0]->getEntityType()->getId();
		$entityIds = array_values(array_unique(array_map(static fn(Item $item) => (int)$item->getId(), $persisted)));
		$loadedByEntityId = $this->fetchBulk($entityTypeId, $entityIds);

		foreach ($persisted as $item)
		{
			$item->internalSet(Item::lastCommunication, $loadedByEntityId[(int)$item->getId()] ?? new LastCommunication());
		}
	}

	/**
	 * @param int[] $entityIds
	 * @return array<int, LastCommunication>
	 */
	private function fetchBulk(int $entityTypeId, array $entityIds): array
	{
		$valuesByEntityId = [];
		foreach (array_chunk($entityIds, self::BATCH_SIZE) as $entityIdsChunk)
		{
			foreach ($this->fetchRowsChunk($entityTypeId, $entityIdsChunk) as $row)
			{
				$entityId = (int)$row['ENTITY_ID'];
				$valuesByEntityId[$entityId] ??= [];
				$lastCommunicationTime = $row['LAST_COMMUNICATION_TIME'];

				$fieldName = match ($row['TYPE'])
				{
					LastCommunicationTable::ENUM_LAST_TIME => 'communicationTime',
					LastCommunicationTable::ENUM_CALL_TIME => 'callTime',
					LastCommunicationTable::ENUM_EMAIL_TIME => 'emailTime',
					LastCommunicationTable::ENUM_IM_OPEN_LINES_TIME => 'imolTime',
					LastCommunicationTable::ENUM_WEB_FORM_TIME => 'webformTime',
					default => null,
				};
				if ($fieldName !== null)
				{
					$valuesByEntityId[$entityId][$fieldName] = $lastCommunicationTime;
				}
			}
		}

		$result = [];
		foreach ($valuesByEntityId as $entityId => $values)
		{
			$result[$entityId] = new LastCommunication(
				communicationTime: $values['communicationTime'] ?? null,
				callTime: $values['callTime'] ?? null,
				emailTime: $values['emailTime'] ?? null,
				imolTime: $values['imolTime'] ?? null,
				webformTime: $values['webformTime'] ?? null,
			);
		}

		return $result;
	}

	/** @param int[] $entityIds */
	protected function fetchRowsChunk(int $entityTypeId, array $entityIds): iterable
	{
		$iterator = LastCommunicationTable::query()
			->setSelect(['ENTITY_ID', 'TYPE', 'LAST_COMMUNICATION_TIME'])
			->where('ENTITY_TYPE_ID', $entityTypeId)
			->whereIn('ENTITY_ID', $entityIds)
			->whereIn('TYPE', [
				LastCommunicationTable::ENUM_LAST_TIME,
				LastCommunicationTable::ENUM_CALL_TIME,
				LastCommunicationTable::ENUM_EMAIL_TIME,
				LastCommunicationTable::ENUM_IM_OPEN_LINES_TIME,
				LastCommunicationTable::ENUM_WEB_FORM_TIME,
			])
			->exec()
		;
		while ($row = $iterator->fetch())
		{
			yield $row;
		}
	}
}
