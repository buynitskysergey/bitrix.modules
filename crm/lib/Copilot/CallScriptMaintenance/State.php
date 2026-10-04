<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\CallScriptMaintenance;

final class State
{
	public const STEP_IDLE       = 'idle';
	public const STEP_GROUPING   = 'grouping';
	public const STEP_ENRICHMENT = 'enrichment';

	public const SUBSTATE_READY        = 'ready';
	public const SUBSTATE_AWAITING_AI  = 'awaiting_ai';

	public const JOB_TYPE_GROUPING = 'grouping';
	public const JOB_TYPE_CREATE_CALL_ASSESSMENT  = 'create_call_assessment';
	public const JOB_TYPE_ENRICH_CALL_ASSESSMENT  = 'enrich_call_assessment';
	public const JOB_TYPE_BACKFILL_CALL_ASSESSMENT = 'backfill_call_assessment';

	private const ALLOWED_STEPS = [self::STEP_IDLE, self::STEP_GROUPING, self::STEP_ENRICHMENT];
	private const ALLOWED_SUBSTATES = [self::SUBSTATE_READY, self::SUBSTATE_AWAITING_AI];

	public string $currentStep = self::STEP_IDLE;
	public string $stepState = self::SUBSTATE_READY;

	public ?string $lastCycleCompletedAt = null;

	public ?int $awaitingJobId = null;
	public ?string $awaitingSince = null;

	/** @var int[] */
	public array $enrichmentPendingCallAssessmentIds = [];

	public ?int $enrichmentCurrentCallAssessmentId = null;

	public int $aiRetryFailureCount = 0;
	public ?string $aiRetryLastFailureAt = null;

	public function toArray(): array
	{
		return [
			'currentStep' => $this->currentStep,
			'stepState' => $this->stepState,
			'lastCycleCompletedAt' => $this->lastCycleCompletedAt,
			'awaitingJobId' => $this->awaitingJobId,
			'awaitingSince' => $this->awaitingSince,
			'enrichmentPendingCallAssessmentIds' => $this->enrichmentPendingCallAssessmentIds,
			'enrichmentCurrentCallAssessmentId' => $this->enrichmentCurrentCallAssessmentId,
			'aiRetryFailureCount' => $this->aiRetryFailureCount,
			'aiRetryLastFailureAt' => $this->aiRetryLastFailureAt,
		];
	}

	public static function fromArray(array $data): self
	{
		$state = new self();

		$state->currentStep = self::sanitizeEnum(
			$data['currentStep'] ?? null,
			self::ALLOWED_STEPS,
			self::STEP_IDLE,
		);
		$state->stepState = self::sanitizeEnum(
			$data['stepState'] ?? null,
			self::ALLOWED_SUBSTATES,
			self::SUBSTATE_READY,
		);
		$state->lastCycleCompletedAt = isset($data['lastCycleCompletedAt'])
			? (string)$data['lastCycleCompletedAt']
			: null
		;
		$state->awaitingJobId = isset($data['awaitingJobId'])
			? (int)$data['awaitingJobId']
			: null
		;
		$state->awaitingSince = isset($data['awaitingSince'])
			? (string)$data['awaitingSince']
			: null
		;
		$state->enrichmentPendingCallAssessmentIds = array_map(
			'intval',
			(array)($data['enrichmentPendingCallAssessmentIds'] ?? [])
		);
		$state->enrichmentCurrentCallAssessmentId = isset($data['enrichmentCurrentCallAssessmentId'])
			? (int)$data['enrichmentCurrentCallAssessmentId']
			: null
		;
		$state->aiRetryFailureCount = isset($data['aiRetryFailureCount'])
			? max(0, (int)$data['aiRetryFailureCount'])
			: 0
		;
		$state->aiRetryLastFailureAt = isset($data['aiRetryLastFailureAt'])
			? (string)$data['aiRetryLastFailureAt']
			: null
		;

		return $state;
	}

	private static function sanitizeEnum(mixed $value, array $allowed, string $default): string
	{
		$value = (string)$value;

		return in_array($value, $allowed, true) ? $value : $default;
	}

	public function resetToIdle(string $now): void
	{
		$this->currentStep = self::STEP_IDLE;
		$this->stepState = self::SUBSTATE_READY;
		$this->lastCycleCompletedAt = $now;
		$this->awaitingJobId = null;
		$this->awaitingSince = null;
		$this->enrichmentPendingCallAssessmentIds = [];
		$this->enrichmentCurrentCallAssessmentId = null;
		$this->aiRetryFailureCount = 0;
		$this->aiRetryLastFailureAt = null;
	}
}
