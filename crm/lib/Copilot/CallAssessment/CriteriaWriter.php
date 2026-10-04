<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\CallAssessment;

use Bitrix\Crm\Copilot\CallAssessment\Controller\CopilotCallAssessmentCriteriaController;
use Bitrix\Main\ORM\Data\AddResult;
use Bitrix\Main\Result;

final class CriteriaWriter
{
	private const SORT_STEP = 100;

	private CopilotCallAssessmentCriteriaController $controller;

	public function __construct()
	{
		$this->controller = CopilotCallAssessmentCriteriaController::getInstance();
	}

	/**
	 * @param iterable<array{title?: string, description?: string}> $criteria
	 * @return array<AddResult>
	 */
	public function append(int $assessmentId, iterable $criteria): array
	{
		$results = [];
		$nextSort = $this->nextSort($assessmentId);

		foreach ($criteria as $criterion)
		{
			$title = trim($criterion['title'] ?? '');
			$description = trim($criterion['description'] ?? '');

			if ($title === '' || $description === '')
			{
				continue;
			}

			$results[] = $this->controller->add([
				'ASSESSMENT_ID' => $assessmentId,
				'TITLE' => $title,
				'DESCRIPTION' => $description,
				'SORT' => $nextSort,
			]);
			$nextSort += self::SORT_STEP;
		}

		return $results;
	}

	/**
	 * @param array<array{id?: int|null, title?: string, description?: string, sort?: int}> $criteria
	 */
	public function sync(int $assessmentId, array $criteria): Result
	{
		$result = new Result();

		$existingIds = array_map(
			static fn(array $row): int => (int)$row['ID'],
			$this->controller->getList([
				'select' => ['ID'],
				'filter' => ['=ASSESSMENT_ID' => $assessmentId],
			]),
		);
		$existingIdsLookup = array_fill_keys($existingIds, true);

		$keptIds = [];

		foreach ($criteria as $criterion)
		{
			if (!is_array($criterion))
			{
				continue;
			}

			$title = trim($criterion['title'] ?? '');
			$description = trim($criterion['description'] ?? '');

			if ($title === '' || $description === '')
			{
				continue;
			}

			$row = [
				'TITLE' => $title,
				'DESCRIPTION' => $description,
				'SORT' => (int)($criterion['sort'] ?? 0),
			];
			$criterionId = (int)($criterion['id'] ?? 0);

			if ($criterionId > 0 && isset($existingIdsLookup[$criterionId]))
			{
				$keptIds[$criterionId] = true;
				$opResult = $this->controller->update($criterionId, $row);
			}
			else
			{
				$row['ASSESSMENT_ID'] = $assessmentId;
				$opResult = $this->controller->add($row);
			}

			if (!$opResult->isSuccess())
			{
				$result->addErrors($opResult->getErrors());
			}
		}

		foreach ($existingIds as $existingId)
		{
			if (isset($keptIds[$existingId]))
			{
				continue;
			}

			$deleteResult = $this->controller->deleteById($existingId);
			if ($deleteResult !== null && !$deleteResult->isSuccess())
			{
				$result->addErrors($deleteResult->getErrors());
			}
		}

		return $result;
	}

	private function nextSort(int $assessmentId): int
	{
		$rows = $this->controller->getList([
			'select' => ['SORT'],
			'filter' => ['=ASSESSMENT_ID' => $assessmentId],
			'order' => ['SORT' => 'DESC'],
			'limit' => 1,
		]);

		if (empty($rows))
		{
			return self::SORT_STEP;
		}

		return $rows[0]['SORT'] + self::SORT_STEP;
	}
}
