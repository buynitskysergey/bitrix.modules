<?php

namespace Bitrix\Crm\Kanban\Entity;

use Bitrix\Crm\Item\Quote as QuoteItem;
use Bitrix\Crm\Kanban\Entity\Deadlines\DeadlinesStageManager;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

class QuoteDeadlines extends Quote
{
	private DeadlinesStageManager $deadlinesManager;

	private string $dateFieldName;

	public function __construct()
	{
		parent::__construct();

		$this->deadlinesManager = new DeadlinesStageManager($this->getTypeId());
		$this->dateFieldName = DeadlinesStageManager::dateFieldByEntityType($this->getTypeId());
	}

	public function getStageFieldName(): string
	{
		return DeadlinesStageManager::FILTER_FIELD_CONTAINS_STAGE;
	}

	public function getDbStageFieldName(): string
	{
		return parent::getStageFieldName();
	}

	public function getStagesList(): array
	{
		return $this->deadlinesManager->stagesList();
	}

	public function fillStageTotalSums(array $filter, array $runtime, array &$stages): void
	{
		foreach ($stages as &$stage)
		{
			$stageFilter = $this->deadlinesManager->applyStageFilter($stage['id'], $filter);

			$dbResult = \CCrmQuote::GetList(
				[],
				$stageFilter,
				['COUNT' => 'ID'],
				false,
				['ID'],
				[]
			);
			$res = $dbResult->Fetch();
			$stage['count'] = $res ? (int) $res['ID'] : 0;
		}
	}

	public function getItems(array $parameters): \CDBResult
	{
		$parameters = $this->deadlinesManager->prepareItemsFilter($parameters);
		$rawResult = parent::getItems($parameters);
		$columnId = $parameters['columnId'] ?? '';

		$items = $this->deadlinesManager->prepareItemsResult($rawResult, $columnId);

		$dbResult = new \CDBResult();
		$dbResult->InitFromArray($items);
		return $dbResult;
	}

	public function isTotalPriceSupported(): bool
	{
		return false;
	}

	public function isRecurringSupported(): bool
	{
		return false;
	}

	public function isExclusionSupported(): bool
	{
		return false;
	}

	public function updateItemStage(int $id, string $stageId, array $newStateParams, array $stages): Result
	{
		if (!$this->deadlinesManager->checkIsStageAllowed($stageId))
		{
			return (new Result())->addError(
				new Error(Loc::getMessage('CRM_KANBAN_DEADLINE_VIEW_MODE_MOVE_ITEM_TO_COLUMN_BLOCKED'))
			);
		}

		/** @var $item QuoteItem */
		$item = $this->factory->getItem($id);
		if (!$item)
		{
			return (new Result())->addError(new Error(Loc::getMessage('CRM_TYPE_ITEM_NOT_FOUND')));
		}

		$item->set($this->dateFieldName, $this->deadlinesManager->calculateDateByStage($stageId));

		return
			$this->factory
				->getUpdateOperation($item)
				->launch();
	}

	public function isActivityCountersFilterSupported(): bool
	{
		return $this->factory->isCountersEnabled();
	}

	public function getRequiredFieldsByStages(array $stages): array
	{
		return [];
	}
}
