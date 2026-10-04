<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\CallAssessment\Summary\Processor;

use Bitrix\Crm\Copilot\CallAssessment\Summary\CallHydrator;
use Bitrix\Crm\Copilot\CallAssessment\Summary\Detector\StreakDetector;
use Bitrix\Crm\Copilot\CallAssessment\Summary\Formatter\StreakMessageFormatter;
use Bitrix\Crm\Copilot\CallAssessment\Summary\Formatter\SubjectSanitizer;
use Bitrix\Crm\Copilot\CallAssessment\Summary\Settings;
use Bitrix\Crm\Copilot\CallAssessment\Summary\Situation;
use Bitrix\Crm\Copilot\CallAssessment\Summary\State\StreakStateRepository;
use Bitrix\Crm\Integration\Imbot\CallScoringV2SummaryBotAccess;
use Bitrix\Crm\Service\Container;

final class StreakSituationProcessor
{
	public function __construct(
		private readonly ?StreakDetector $detector = null,
		private readonly ?StreakMessageFormatter $formatter = null,
		private readonly ?StreakStateRepository $stateRepo = null,
		private readonly ?CallHydrator $hydrator = null,
	) {}

	public function process(Situation $situation, int $managerId, Settings $settings): void
	{
		if ($managerId <= 0)
		{
			return;
		}

		if (!CallScoringV2SummaryBotAccess::isAvailable())
		{
			return;
		}

		if (!in_array($situation, [Situation::GoodStreak, Situation::BadStreak], true))
		{
			return;
		}

		$config = $settings->situations[$situation->value] ?? null;
		if (!is_array($config) || empty($config['enabled']))
		{
			return;
		}

		$requiredLength = (int)($config['threshold'] ?? $situation->getDefaultThreshold());

		$detector = $this->detector ?? new StreakDetector();

		$streak = $detector->detectStreakLength($managerId, $situation, $requiredLength);
		if ($streak === null)
		{
			return;
		}

		$assessmentIds = $streak['assessmentIds'];

		$stateRepo = $this->stateRepo ?? new StreakStateRepository();
		$lastNotifiedId = $stateRepo->getLastNotifiedAssessmentId($managerId, $situation);
		if ($lastNotifiedId > 0 && in_array($lastNotifiedId, $assessmentIds, true))
		{
			return;
		}

		$streakCalls = ($this->hydrator ?? new CallHydrator())->hydrateCalls($assessmentIds);
		if ($streakCalls === [])
		{
			return;
		}

		$this->dispatch(
			$situation,
			$managerId,
			$requiredLength,
			$streak['avgAssessment'],
			$streak['thresholdValue'],
			$streakCalls,
			$settings->sendSelfDigest,
		);

		$stateRepo->setLastNotifiedAssessmentId($managerId, $situation, $assessmentIds[0]);
	}

	/**
	 * @param list<array{id:int, activityId:int, ownerTypeId:int, ownerId:int, jobId:int, assessment:int, subject:string}> $streakCalls
	 */
	private function dispatch(
		Situation $situation,
		int $managerId,
		int $streakLength,
		int $avgAssessment,
		int $thresholdValue,
		array $streakCalls,
		bool $sendSelfDigest,
	): void
	{
		$bot = CallScoringV2SummaryBotAccess::getBotIfAvailable();
		if ($bot === null)
		{
			return;
		}

		$formatter = $this->formatter ?? new StreakMessageFormatter();

		$managerName = SubjectSanitizer::sanitize(Container::getInstance()->getUserBroker()->getName($managerId) ?? '');
		$keyboard = $bot->buildSituationKeyboard($managerId);

		$bot->broadcast(
			$formatter->formatForRecipients(
				$situation,
				$managerName,
				$streakLength,
				$avgAssessment,
				$thresholdValue,
				$streakCalls,
			),
			$keyboard,
			$sendSelfDigest ? [$managerId] : [],
		);

		if ($sendSelfDigest)
		{
			$bot->notifyManager(
				$managerId,
				$formatter->formatForManager(
					$situation,
					$streakLength,
					$avgAssessment,
					$thresholdValue,
					$streakCalls,
				),
				$keyboard,
			);
		}
	}
}
