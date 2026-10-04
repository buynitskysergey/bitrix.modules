<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\CallScriptMaintenance;

use Bitrix\Crm\Copilot\AiCallScriptSelection\Entity\AiCallScriptSelectionTable;
use Bitrix\Crm\Copilot\CallAssessment\CallAssessmentItem;
use Bitrix\Crm\Copilot\CallAssessment\Controller\CopilotCallAssessmentController;
use Bitrix\Crm\Copilot\CallAssessment\Entity\CopilotCallAssessment;
use Bitrix\Crm\Copilot\CallAssessment\Entity\CopilotCallAssessmentCriteriaTable;
use Bitrix\Crm\Copilot\CallAssessment\Entity\CopilotCallAssessmentTable;
use Bitrix\Crm\Integration\AI\JobRepository;
use Bitrix\Crm\Integration\AI\Model\QueueTable;
use Bitrix\Crm\Integration\AI\Operation\TranscribeCallRecording;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use CCrmOwnerType;

final class CandidatesRepository
{
	public const AI_QUEUE_STATUS_PENDING = 'PENDING';
	public const AI_QUEUE_STATUS_SUCCESS = 'SUCCESS';
	public const AI_QUEUE_STATUS_ERROR   = 'ERROR';
	public const AI_QUEUE_STATUS_MISSING = 'MISSING';
	private const CALL_ASSESSMENTS_LIMIT = 1000;

	public function countSuspiciousNotGrouped(int $confidenceThreshold): int
	{
		$row = AiCallScriptSelectionTable::query()
			->addSelect(new ExpressionField('CNT', 'COUNT(*)'))
			->where('GROUPED_AT', null)
			->where('CONFIDENCE', '<', $confidenceThreshold)
			->fetch()
		;

		return (int)($row['CNT'] ?? 0);
	}

	public function loadSuspiciousBatch(int $confidenceThreshold, int $limit): array
	{
		$res = AiCallScriptSelectionTable::query()
			->setSelect([
				'ID',
				'ACTIVITY_ID',
				'THEME' => 'SUMMARY.THEME',
				'PRODUCT' => 'SUMMARY.PRODUCT',
				'INTENT' => 'SUMMARY.INTENT',
			])
			->where('GROUPED_AT', null)
			->where('CONFIDENCE', '<', $confidenceThreshold)
			->setOrder(['ID' => 'ASC'])
			->setLimit($limit)
			->exec()
		;

		$result = [];
		while ($row = $res->fetch())
		{
			$result[] = [
				'selectionId' => (int)$row['ID'],
				'activityId' => (int)$row['ACTIVITY_ID'],
				'theme' => $row['THEME'] ?? null,
				'product' => $row['PRODUCT'] ?? null,
				'intent' => $row['INTENT'] ?? null,
			];
		}

		return $result;
	}

	public function loadCallAssessments(): array
	{
		$items = CopilotCallAssessmentController::getInstance()->getList([
			'select' => ['ID', 'TITLE', 'DESCRIPTION'],
			'filter' => ['IS_ENABLED' => 'Y'],
			'order' => ['ID' => 'ASC'],
			'limit' => self::CALL_ASSESSMENTS_LIMIT,
		]);

		$result = [];
		/** @var CopilotCallAssessment $callAssessment */
		foreach ($items as $callAssessment)
		{
			$result[] = [
				'id' => (int)$callAssessment->getId(),
				'name' => (string)$callAssessment->getTitle(),
				'description' => (string)$callAssessment->getDescription(),
			];
		}

		return $result;
	}

	public function findCallAssessmentsWithEnoughEnrichmentCandidates(int $confidenceThreshold, int $minCalls): array
	{
		$rows = AiCallScriptSelectionTable::query()
			->setSelect(['ASSESSMENT_SETTING_ID'])
			->addSelect(new ExpressionField('CNT', 'COUNT(DISTINCT %s)', 'ACTIVITY_ID'))
			->where('ENRICHED_AT', null)
			->where('CONFIDENCE', '>=', $confidenceThreshold)
			->where('ASSESSMENT_SETTING_ID', '>', 0)
			->where('ASSESSMENT.IS_ENABLED', 'Y')
			->where('ASSESSMENT.IS_AI_IMPROVEMENT_ENABLED', 'Y')
			->setGroup(['ASSESSMENT_SETTING_ID'])
			->having('CNT', '>=', $minCalls)
			->setOrder(['CNT' => 'DESC', 'ASSESSMENT_SETTING_ID' => 'ASC'])
			->fetchAll()
		;

		return array_map(static fn ($row) => (int)$row['ASSESSMENT_SETTING_ID'], $rows);
	}

	/**
	 * @return array<int, array{selectionId: int, activityId: int}>
	 */
	public function loadSelectionIdsForCallAssessment(int $callAssessmentId, int $confidenceThreshold, int $limit): array
	{
		$activityRows = AiCallScriptSelectionTable::query()
			->setSelect(['ACTIVITY_ID'])
			->addSelect(new ExpressionField('MIN_ID', 'MIN(%s)', 'ID'))
			->addSelect(new ExpressionField('MAX_ID', 'MAX(%s)', 'ID'))
			->where('ENRICHED_AT', null)
			->where('CONFIDENCE', '>=', $confidenceThreshold)
			->where('ASSESSMENT_SETTING_ID', $callAssessmentId)
			->setGroup(['ACTIVITY_ID'])
			->setOrder(['MIN_ID' => 'ASC'])
			->setLimit($limit)
			->fetchAll()
		;

		if (empty($activityRows))
		{
			return [];
		}

		$activityIds = array_map(static fn ($row) => (int)$row['ACTIVITY_ID'], $activityRows);
		$idCap = max(array_map(static fn ($row) => (int)$row['MAX_ID'], $activityRows));

		$selectionRows = AiCallScriptSelectionTable::query()
			->setSelect(['ID', 'ACTIVITY_ID'])
			->where('ENRICHED_AT', null)
			->where('CONFIDENCE', '>=', $confidenceThreshold)
			->where('ASSESSMENT_SETTING_ID', $callAssessmentId)
			->whereIn('ACTIVITY_ID', $activityIds)
			->where('ID', '<=', $idCap)
			->setOrder(['ID' => 'ASC'])
			->fetchAll()
		;

		$result = [];
		foreach ($selectionRows as $row)
		{
			$result[] = [
				'selectionId' => (int)$row['ID'],
				'activityId' => (int)$row['ACTIVITY_ID'],
			];
		}

		return $result;
	}

	public function markUnenrichableSelections(int $confidenceThreshold): void
	{
		$unenrichableCondition = (new ConditionTree())
			->logic(ConditionTree::LOGIC_OR)
			->whereNull('ASSESSMENT.ID')
			->where('ASSESSMENT.IS_ENABLED', 'N')
			->where('ASSESSMENT.IS_AI_IMPROVEMENT_ENABLED', 'N')
		;

		$rows = AiCallScriptSelectionTable::query()
			->setSelect(['ID'])
			->where('ENRICHED_AT', null)
			->where('CONFIDENCE', '>=', $confidenceThreshold)
			->where('ASSESSMENT_SETTING_ID', '>', 0)
			->where($unenrichableCondition)
			->fetchAll()
		;

		$ids = array_map(static fn ($row) => (int)$row['ID'], $rows);
		if (empty($ids))
		{
			return;
		}

		AiCallScriptSelectionTable::markEnrichedByIds($ids);
	}

	/**
	 * @return int[]
	 */
	public function findCallAssessmentsWithoutCriteria(): array
	{
		$withCriteriaRows = CopilotCallAssessmentCriteriaTable::query()
			->setSelect(['ASSESSMENT_ID'])
			->setGroup(['ASSESSMENT_ID'])
			->fetchAll()
		;
		$withCriteriaIds = array_map(static fn($row) => (int)$row['ASSESSMENT_ID'], $withCriteriaRows);

		$query = CopilotCallAssessmentTable::query()
			->setSelect(['ID'])
			->where('STATUS', '!=', CallAssessmentItem::STATUS_GENERATING_FROM_DIALOG)
			->setOrder(['ID' => 'ASC'])
		;
		if (!empty($withCriteriaIds))
		{
			$query->whereNotIn('ID', $withCriteriaIds);
		}

		return array_map(
			static fn($row) => (int)$row['ID'],
			$query->fetchAll(),
		);
	}

	/**
	 * @param int[] $activityIds
	 * @return array<int, string> activityId => transcript
	 */
	public function loadTranscriptsForActivities(array $activityIds): array
	{
		if (empty($activityIds))
		{
			return [];
		}

		$jobRepo = JobRepository::getInstance();
		$result = [];
		foreach ($activityIds as $activityId)
		{
			$activityId = (int)$activityId;
			$jobResult = $jobRepo->getTranscribeCallRecordingResultByActivity($activityId);
			if ($jobResult === null || !$jobResult->isSuccess())
			{
				continue;
			}

			$payload = $jobResult->getPayload();
			$transcript = is_object($payload) && property_exists($payload, 'transcription')
				? trim((string)$payload->transcription)
				: '';

			if ($transcript !== '')
			{
				$result[$activityId] = $transcript;
			}
		}

		return $result;
	}

	/**
	 * @return int[]
	 */
	public function findRecentTranscribedActivityIds(int $limit): array
	{
		$rows = QueueTable::query()
			->setSelect(['ENTITY_ID'])
			->where('TYPE_ID', TranscribeCallRecording::TYPE_ID)
			->where('EXECUTION_STATUS', QueueTable::EXECUTION_STATUS_SUCCESS)
			->where('ENTITY_TYPE_ID', CCrmOwnerType::Activity)
			->setOrder(['ID' => 'DESC'])
			->setLimit($limit)
			->fetchAll()
		;

		$activityIds = [];
		foreach ($rows as $row)
		{
			$id = (int)$row['ENTITY_ID'];
			if ($id > 0)
			{
				$activityIds[$id] = true;
			}
		}

		return array_keys($activityIds);
	}

	public function aiQueueStatus(int $jobId): string
	{
		if ($jobId <= 0)
		{
			return self::AI_QUEUE_STATUS_MISSING;
		}

		$row = QueueTable::query()
			->setSelect(['EXECUTION_STATUS'])
			->where('ID', $jobId)
			->fetch()
		;

		if (!$row)
		{
			return self::AI_QUEUE_STATUS_MISSING;
		}

		$status = (string)$row['EXECUTION_STATUS'];

		return match ($status)
		{
			QueueTable::EXECUTION_STATUS_PENDING => self::AI_QUEUE_STATUS_PENDING,
			QueueTable::EXECUTION_STATUS_SUCCESS => self::AI_QUEUE_STATUS_SUCCESS,
			QueueTable::EXECUTION_STATUS_ERROR   => self::AI_QUEUE_STATUS_ERROR,
			default => self::AI_QUEUE_STATUS_MISSING,
		};
	}
}
