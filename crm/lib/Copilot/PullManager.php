<?php

namespace Bitrix\Crm\Copilot;

use Bitrix\Main\Loader;
use CPullWatch;

final class PullManager
{
	public const ADD_CALL_SCORING_PULL_COMMAND = 'call_scoring_add';
	public const UPDATE_CALL_ASSESSMENT_PULL_COMMAND = 'call_assessment_update';
	public const CALL_ASSESSMENT_CREATE_COMPLETE_PULL_COMMAND = 'call_assessment_create_complete';

	/** @var CPullWatch|string|null */
	protected $pullWatch = null;

	public function __construct()
	{
		if ($this->includePullModule())
		{
			$this->pullWatch = CPullWatch::class;
		}
	}

	private function includePullModule(): bool
	{
		return Loader::includeModule('pull');
	}

	public function sendAddScoringPullEvent(int $activityId, array $params): void
	{
		if (!$this->includePullModule())
		{
			return;
		}

		$pushParams = [
			'activityId' => $activityId,
			'jobId' => $params['jobId'] ?? null,
			'ratedUserId' => $params['ratedUserId'] ?? null,
			'assessmentSettingsId' => $params['assessmentSettingsId'] ?? null,
			'status' => $params['status'] ?? null,
		];

		$this->pullWatch::AddToStack(
			self::ADD_CALL_SCORING_PULL_COMMAND,
			[
				'module_id' => 'crm',
				'command' => self::ADD_CALL_SCORING_PULL_COMMAND,
				'params' => $pushParams,
			]
		);
	}

	public function sendUpdateAssessmentPullEvent(int $assessmentSettingsId, array $data): void
	{
		if (!$this->includePullModule())
		{
			return;
		}

		$pushParams = [
			'assessmentSettingsId' => $assessmentSettingsId,
			'eventId' => $data['eventId'] ?? null,
		];

		$this->pullWatch::AddToStack(
			self::UPDATE_CALL_ASSESSMENT_PULL_COMMAND,
			[
				'module_id' => 'crm',
				'command' => self::UPDATE_CALL_ASSESSMENT_PULL_COMMAND,
				'params' => $pushParams,
			]
		);
	}

	public function subscribe(int $userId, int $activityId): void
	{
		if (!$this->includePullModule())
		{
			return;
		}

		$this->pullWatch::Add($userId, self::ADD_CALL_SCORING_PULL_COMMAND);
		$this->pullWatch::Add($userId, self::UPDATE_CALL_ASSESSMENT_PULL_COMMAND);
	}

	public function sendCreateCompletePullEvent(int $userId, int $assessmentId): void
	{
		if (!$this->includePullModule() || $userId <= 0)
		{
			return;
		}

		$message = [
			'module_id' => 'crm',
			'command' => self::CALL_ASSESSMENT_CREATE_COMPLETE_PULL_COMMAND,
			'params' => [
				'assessmentId' => $assessmentId,
			],
		];

		\CPullStack::AddByUser($userId, $message);
	}
}
