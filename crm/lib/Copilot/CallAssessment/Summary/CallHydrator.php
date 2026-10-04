<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\CallAssessment\Summary;

use Bitrix\Crm\ActivityTable;
use Bitrix\Crm\Copilot\AiCallSummary\Entity\AiCallSummaryTable;
use Bitrix\Crm\Copilot\AiQualityAssessment\Entity\AiQualityAssessmentTable;
use Bitrix\Main\ORM\Fields\Relations\Reference;

final class CallHydrator
{
	/**
	 * @param list<int> $assessmentIds
	 *
	 * @return list<array{id:int, activityId:int, ownerTypeId:int, ownerId:int, jobId:int, assessment:int, subject:string}>
	 */
	public function hydrateCalls(array $assessmentIds): array
	{
		if ($assessmentIds === [])
		{
			return [];
		}

		$rows = AiQualityAssessmentTable::query()
			->registerRuntimeField('ACTIVITY', new Reference(
				'ACTIVITY',
				ActivityTable::class,
				['=this.ACTIVITY_ID' => 'ref.ID'],
			))
			->registerRuntimeField('CALL_SUMMARY', new Reference(
				'CALL_SUMMARY',
				AiCallSummaryTable::class,
				['=this.ACTIVITY_ID' => 'ref.ACTIVITY_ID'],
			))
			->setSelect([
				'ID',
				'ASSESSMENT',
				'ACTIVITY_ID',
				'JOB_ID',
				'ACTIVITY_OWNER_TYPE_ID' => 'ACTIVITY.OWNER_TYPE_ID',
				'ACTIVITY_OWNER_ID' => 'ACTIVITY.OWNER_ID',
				'CALL_THEME' => 'CALL_SUMMARY.THEME',
			])
			->whereIn('ID', $assessmentIds)
			->fetchAll()
		;

		$byId = [];
		foreach ($rows as $row)
		{
			$id = (int)$row['ID'];
			$byId[$id] = [
				'id' => $id,
				'activityId' => (int)$row['ACTIVITY_ID'],
				'ownerTypeId' => (int)($row['ACTIVITY_OWNER_TYPE_ID'] ?? 0),
				'ownerId' => (int)($row['ACTIVITY_OWNER_ID'] ?? 0),
				'jobId' => (int)$row['JOB_ID'],
				'assessment' => (int)$row['ASSESSMENT'],
				'subject' => (string)($row['CALL_THEME'] ?? ''),
			];
		}

		$result = [];
		foreach ($assessmentIds as $id)
		{
			if (isset($byId[$id]))
			{
				$result[] = $byId[$id];
			}
		}

		return $result;
	}
}
