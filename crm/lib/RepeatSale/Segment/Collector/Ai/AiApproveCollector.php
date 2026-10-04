<?php

namespace Bitrix\Crm\RepeatSale\Segment\Collector\Ai;

use Bitrix\Crm\RepeatSale\Service\Entity\RepeatSaleAiScreeningTable;
use Bitrix\Crm\RepeatSale\Service\Handler\AiScreeningOpinion;
use Bitrix\Main\Type\Date;

final class AiApproveCollector extends BaseAiCollector
{
	// older approved rows are outdated: their holiday has already passed
	// wider than the window of RemainingCollector on purpose: a verdict may come late because of the
	// scheduler step, call transcription or AI queue retries
	private const OLDEST_DESIRED_CREATION_DATE_INTERVAL = '-2 days';

	protected function getItems(int $entityTypeId, array $filter): array
	{
		return RepeatSaleAiScreeningTable::query()
			->setSelect(['ID', 'OWNER_ID'])
			->setFilter([
				'=OWNER_TYPE_ID' => $entityTypeId,
				'=AI_OPINION' => AiScreeningOpinion::isRepeatSalePossible->value,
				'=RESULT_ENTITY_TYPE_ID' => null,
				'=RESULT_ENTITY_ID' => null,
				'>=DESIRED_CREATION_DATE' => (new Date())->add(self::OLDEST_DESIRED_CREATION_DATE_INTERVAL),
				'<=DESIRED_CREATION_DATE' => (new Date())->add('1 day'),
				'>ID' => $filter['>ID'] ?? 0,
			])
			->setOrder(['ID' => 'ASC'])
			->setLimit($this->limit)
			->fetchAll()
		;
	}

	protected function getFilteredItemIds(array $items, array $filter): array
	{
		return array_column($items, 'OWNER_ID');
	}
}
