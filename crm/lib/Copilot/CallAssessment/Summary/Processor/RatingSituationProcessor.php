<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\CallAssessment\Summary\Processor;

use Bitrix\Crm\Copilot\CallAssessment\Summary\CallHydrator;
use Bitrix\Crm\Copilot\CallAssessment\Summary\Detector\RatingDetector;
use Bitrix\Crm\Copilot\CallAssessment\Summary\Formatter\RatingMessageFormatter;
use Bitrix\Crm\Copilot\CallAssessment\Summary\Formatter\SubjectSanitizer;
use Bitrix\Crm\Copilot\CallAssessment\Summary\Settings;
use Bitrix\Crm\Copilot\CallAssessment\Summary\Situation;
use Bitrix\Crm\Copilot\CallAssessment\Summary\State\RatingStateRepository;
use Bitrix\Crm\Integration\Imbot\CallScoringV2SummaryBotAccess;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Type\DateTime;

final class RatingSituationProcessor
{
	private const MIN_INTERVAL_SECONDS = 86400; // 24h
	private const FIRST_NOTIFY_LOOKBACK = '-7 days'; // S1: first notification → "last 7 days" window

	public function __construct(
		private readonly ?RatingDetector $detector = null,
		private readonly ?RatingMessageFormatter $formatter = null,
		private readonly ?RatingStateRepository $stateRepo = null,
		private readonly ?CallHydrator $hydrator = null,
	) {}

	public function process(
		Situation $situation,
		int $managerId,
		int $currentRating,
		?int $previousRating,
		Settings $settings,
	): void
	{
		if ($managerId <= 0)
		{
			return;
		}

		if (!CallScoringV2SummaryBotAccess::isAvailable())
		{
			return;
		}

		if (!in_array($situation, [Situation::RatingDropped, Situation::RatingRaised], true))
		{
			return;
		}

		$config = $settings->situations[$situation->value] ?? null;
		if (!is_array($config) || empty($config['enabled']))
		{
			return;
		}

		$threshold = (int)($config['threshold'] ?? $situation->getDefaultThreshold());

		$detector = $this->detector ?? new RatingDetector();
		$stateRepo = $this->stateRepo ?? new RatingStateRepository();

		$shouldNotify = $detector->detect($situation, $threshold, $currentRating, $previousRating);
		$status = $stateRepo->fetchStatus($managerId, $situation);

		if ($shouldNotify)
		{
			if ($status['notified'])
			{
				return;
			}

			if ($this->isWithinCooldown($status['lastNotifiedAt']))
			{
				return;
			}

			$recentCalls = $this->collectTopCalls($detector, $situation, $managerId, $status['lastNotifiedAt']);

			$this->dispatch(
				$situation,
				$managerId,
				$currentRating,
				$threshold,
				$recentCalls,
				$settings->sendSelfDigest,
			);

			$stateRepo->markNotified($managerId, $situation);

			return;
		}

		if ($status['notified'])
		{
			$stateRepo->clearNotification($managerId, $situation);
		}
	}

	/**
	 * @return list<array{id:int, activityId:int, ownerTypeId:int, ownerId:int, jobId:int, assessment:int, subject:string}>
	 */
	private function collectTopCalls(
		RatingDetector $detector,
		Situation $situation,
		int $managerId,
		?DateTime $lastNotifiedAt,
	): array
	{
		$since = $lastNotifiedAt ?? (new DateTime())->add(self::FIRST_NOTIFY_LOOKBACK);

		$ids = $detector->getTopAssessmentIds(
			$managerId,
			$situation,
			$since,
			RatingMessageFormatter::TOP_CALLS_LIMIT,
		);
		if ($ids === [])
		{
			return [];
		}

		return ($this->hydrator ?? new CallHydrator())->hydrateCalls($ids);
	}

	private function isWithinCooldown(?DateTime $lastNotifiedAt): bool
	{
		if ($lastNotifiedAt === null)
		{
			return false;
		}

		return (time() - $lastNotifiedAt->getTimestamp()) < self::MIN_INTERVAL_SECONDS;
	}

	/**
	 * @param list<array{id:int, activityId:int, ownerTypeId:int, ownerId:int, jobId:int, assessment:int, subject:string}> $recentCalls
	 */
	private function dispatch(
		Situation $situation,
		int $managerId,
		int $currentRating,
		int $thresholdValue,
		array $recentCalls,
		bool $sendSelfDigest,
	): void
	{
		$bot = CallScoringV2SummaryBotAccess::getBotIfAvailable();
		if ($bot === null)
		{
			return;
		}

		$formatter = $this->formatter ?? new RatingMessageFormatter();

		$managerName = SubjectSanitizer::sanitize(Container::getInstance()->getUserBroker()->getName($managerId) ?? '');
		$keyboard = $bot->buildSituationKeyboard($managerId);

		$bot->broadcast(
			$formatter->formatForRecipients(
				$situation,
				$managerName,
				$currentRating,
				$thresholdValue,
				$recentCalls,
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
					$currentRating,
					$thresholdValue,
					$recentCalls,
				),
				$keyboard,
			);
		}
	}
}
