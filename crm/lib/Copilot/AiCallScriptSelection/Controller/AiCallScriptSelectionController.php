<?php

namespace Bitrix\Crm\Copilot\AiCallScriptSelection\Controller;

use Bitrix\Crm\Copilot\AiCallScriptSelection\Entity\AiCallScriptSelectionItem;
use Bitrix\Crm\Copilot\AiCallScriptSelection\Entity\AiCallScriptSelectionTable;
use Bitrix\Crm\Traits\Singleton;
use Bitrix\Main\ORM\Data\AddResult;
use Bitrix\Main\ORM\Objectify\Collection;

final class AiCallScriptSelectionController
{
	use Singleton;

	public function add(AiCallScriptSelectionItem $item): AddResult
	{
		return AiCallScriptSelectionTable::add($this->getFields($item));
	}

	public function getList(array $params = []): Collection
	{
		$query = AiCallScriptSelectionTable::query()
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
		AiCallScriptSelectionTable::deleteByActivityId($activityId);
	}

	public function deleteByJobIds(array $jobIds): void
	{
		AiCallScriptSelectionTable::deleteByJobIds($jobIds);
	}

	private function getFields(AiCallScriptSelectionItem $item): array
	{
		return [
			'ACTIVITY_ID' => $item->getActivityId(),
			'ASSESSMENT_SETTING_ID' => $item->getAssessmentSettingId(),
			'JOB_ID' => $item->getJobId(),
			'CONFIDENCE' => $item->getConfidence(),
			'RATIONALE' => $item->getRationale(),
		];
	}
}
