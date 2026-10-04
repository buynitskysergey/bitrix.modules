<?php

namespace Bitrix\Crm\Integration\AI\Operation\Payload\Payload\Trait;

use Bitrix\Crm\Copilot\CallAssessment\Controller\CopilotCallAssessmentController;
use Bitrix\Crm\Copilot\CallAssessment\Controller\CopilotCallAssessmentCriteriaController;

trait CallAssessmentDataLoaderTrait
{
	protected function loadScript(int $assessmentSettingsId, array $additionalFilter = []): array
	{
		if ($assessmentSettingsId <= 0)
		{
			return [];
		}

		$filter = array_merge(['ID' => $assessmentSettingsId], $additionalFilter);

		$items = CopilotCallAssessmentController::getInstance()->getList([
			'select' => ['ID', 'TITLE', 'DESCRIPTION'],
			'filter' => $filter,
			'limit' => 1,
		])->collectValues();

		$item = reset($items);
		if (!$item)
		{
			return [];
		}

		return [
			'name' => $item['TITLE'] ?? '',
			'description' => $item['DESCRIPTION'] ?? '',
		];
	}

	/**
	 * Raw dictionary values of the script as they are stored, without any title resolving.
	 *
	 * @return array{callType: int, clientTypeIds: int[]}
	 */
	protected function loadAssessmentTypeIds(int $assessmentSettingsId): array
	{
		$empty = [
			'callType' => 0,
			'clientTypeIds' => [],
		];

		if ($assessmentSettingsId <= 0)
		{
			return $empty;
		}

		$collection = CopilotCallAssessmentController::getInstance()->getList([
			'select' => [
				'ID',
				'CALL_TYPE',
				'CLIENT_TYPES.CLIENT_TYPE_ID',
			],
			'filter' => [
				'=ID' => $assessmentSettingsId,
			],
			'limit' => 1,
		]);

		$entity = null;
		foreach ($collection as $row)
		{
			$entity = $row;

			break;
		}

		if ($entity === null)
		{
			return $empty;
		}

		$clientTypeIds = [];
		foreach ($entity->getClientTypes() ?? [] as $clientType)
		{
			$clientTypeIds[] = (int)$clientType->getClientTypeId();
		}

		return [
			'callType' => (int)$entity->getCallType(),
			'clientTypeIds' => $clientTypeIds,
		];
	}

	protected function loadCriteria(int $assessmentSettingsId): array
	{
		if ($assessmentSettingsId <= 0)
		{
			return [];
		}

		$rows = CopilotCallAssessmentCriteriaController::getInstance()->getList([
			'select' => ['TITLE', 'DESCRIPTION'],
			'filter' => ['=ASSESSMENT_ID' => $assessmentSettingsId],
		]);

		$result = [];
		foreach ($rows as $row)
		{
			$result[] = [
				'name' => $row['TITLE'] ?? '',
				'description' => $row['DESCRIPTION'] ?? '',
			];
		}

		return $result;
	}
}
