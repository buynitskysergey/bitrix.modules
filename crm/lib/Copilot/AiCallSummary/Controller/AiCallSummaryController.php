<?php

namespace Bitrix\Crm\Copilot\AiCallSummary\Controller;

use Bitrix\Crm\Copilot\AiCallSummary\Entity\AiCallSummaryItem;
use Bitrix\Crm\Copilot\AiCallSummary\Entity\AiCallSummaryTable;
use Bitrix\Crm\Traits\Singleton;
use Bitrix\Main\ORM\Data\AddResult;
use Bitrix\Main\ORM\Data\UpdateResult;
use Bitrix\Main\ORM\Objectify\Collection;

final class AiCallSummaryController
{
	use Singleton;

	public function add(AiCallSummaryItem $item): AddResult
	{
		return AiCallSummaryTable::add($this->getFields($item));
	}

	public function update(int $id, AiCallSummaryItem $item): UpdateResult
	{
		return AiCallSummaryTable::update($id, $this->getFields($item));
	}

	public function getByActivityId(int $activityId): ?AiCallSummaryItem
	{
		if ($activityId <= 0)
		{
			return null;
		}

		$row = AiCallSummaryTable::getList([
			'filter' => ['=ACTIVITY_ID' => $activityId],
			'limit' => 1,
		])->fetch();

		return is_array($row) ? AiCallSummaryItem::createFromEntityFields($row) : null;
	}

	public function getList(array $params = []): Collection
	{
		$query = AiCallSummaryTable::query()
			->setSelect($params['select'] ?? ['*'])
			->setFilter($params['filter'] ?? [])
			->setOrder($params['order'] ?? ['ID' => 'DESC'])
			->setOffset($params['offset'] ?? 0)
			->setLimit($params['limit'] ?? 50)
		;

		return $query->exec()->fetchCollection();
	}

	public function deleteByActivityId(int $activityId): void
	{
		AiCallSummaryTable::deleteByActivityId($activityId);
	}

	public function deleteByJobIds(array $jobIds): void
	{
		AiCallSummaryTable::deleteByJobIds($jobIds);
	}

	private function getFields(AiCallSummaryItem $item): array
	{
		return [
			'ACTIVITY_ID' => $item->getActivityId(),
			'JOB_ID' => $item->getJobId(),
			'THEME' => $item->getTheme(),
			'PRODUCT' => $item->getProduct(),
			'INTENT' => $item->getIntent(),
		];
	}
}
