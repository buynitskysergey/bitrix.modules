<?php

namespace Bitrix\Crm\Copilot\CallAssessment;

use Bitrix\Crm\Copilot\CallAssessment\Controller\CopilotCallAssessmentCriteriaController;

final class CriteriaLoader
{
	public function loadForAssessment(int $assessmentId): array
	{
		$grouped = $this->loadForAssessments([$assessmentId]);

		return $grouped[$assessmentId] ?? [];
	}

	/**
	 * @param int[] $assessmentIds
	 * @return array<int, array<int, array{id:int,title:string,description:string,sort:int}>>
	 */
	public function loadForAssessments(array $assessmentIds): array
	{
		$assessmentIds = array_values(array_unique(array_filter(
			$assessmentIds,
			static fn(int $id): bool => $id > 0,
		)));

		if (empty($assessmentIds))
		{
			return [];
		}

		$rows = CopilotCallAssessmentCriteriaController::getInstance()->getList([
			'filter' => [
				'@ASSESSMENT_ID' => $assessmentIds,
			],
			'order' => [
				'SORT' => 'ASC',
				'ID' => 'ASC',
			],
		]);

		$grouped = array_fill_keys($assessmentIds, []);
		foreach ($rows as $row)
		{
			$assessmentId = (int)($row['ASSESSMENT_ID'] ?? 0);
			if ($assessmentId <= 0 || !isset($grouped[$assessmentId]))
			{
				continue;
			}

			$grouped[$assessmentId][] = [
				'id' => (int)$row['ID'],
				'title' => (string)$row['TITLE'],
				'description' => (string)$row['DESCRIPTION'],
				'sort' => (int)($row['SORT'] ?? 0),
			];
		}

		return $grouped;
	}
}
