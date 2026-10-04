<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\CallAssessment\Summary\Formatter;

use Bitrix\Crm\Copilot\CallAssessment\Summary\CallAssessmentDrawerUrl;
use Bitrix\Crm\Copilot\CallAssessment\Summary\Situation;
use Bitrix\Main\Localization\Loc;

final class StreakMessageFormatter
{
	public const TOP_CALLS_LIMIT = 3;

	/**
	 * @param list<array{id:int, activityId:int, ownerTypeId:int, ownerId:int, jobId:int, assessment:int, subject:string}> $streakCalls
	 */
	public function formatForRecipients(
		Situation $situation,
		string $managerName,
		int $streakLength,
		int $avgAssessment,
		int $thresholdValue,
		array $streakCalls = [],
	): string
	{
		return $this->compose(
			$this->loc($situation->getStreakHeaderKey(), [
				'#NAME#' => $managerName,
				'#COUNT#' => (string)$streakLength,
			]),
			$situation,
			$avgAssessment,
			$thresholdValue,
			$streakCalls,
		);
	}

	/**
	 * @param list<array{id:int, activityId:int, ownerTypeId:int, ownerId:int, jobId:int, assessment:int, subject:string}> $streakCalls
	 */
	public function formatForManager(
		Situation $situation,
		int $streakLength,
		int $avgAssessment,
		int $thresholdValue,
		array $streakCalls = [],
	): string
	{
		return $this->compose(
			$this->loc($situation->getStreakSelfHeaderKey(), [
				'#COUNT#' => (string)$streakLength,
			]),
			$situation,
			$avgAssessment,
			$thresholdValue,
			$streakCalls,
		);
	}

	/**
	 * @param list<array{id:int, activityId:int, ownerTypeId:int, ownerId:int, jobId:int, assessment:int, subject:string}> $streakCalls
	 */
	private function compose(
		string $header,
		Situation $situation,
		int $avgAssessment,
		int $thresholdValue,
		array $streakCalls,
	): string
	{
		$blocks = [
			$header,
			$this->formatStats($situation, $avgAssessment, $thresholdValue),
		];

		$topCallsBlock = $this->formatTopCalls($situation, $streakCalls);
		if ($topCallsBlock !== '')
		{
			$blocks[] = $topCallsBlock;
		}

		return implode("\n\n", $blocks);
	}

	private function formatStats(Situation $situation, int $avgAssessment, int $thresholdValue): string
	{
		return $this->loc($situation->getStreakStatsKey(), [
			'#AVG#' => (string)$avgAssessment,
			'#THRESHOLD#' => (string)$thresholdValue,
		]);
	}

	private function loc(?string $key, array $replacements = []): string
	{
		if ($key === null)
		{
			return '';
		}

		return (string)Loc::getMessage($key, $replacements);
	}

	/**
	 * @param list<array{id:int, activityId:int, ownerTypeId:int, ownerId:int, jobId:int, assessment:int, subject:string}> $streakCalls
	 */
	private function formatTopCalls(Situation $situation, array $streakCalls): string
	{
		if ($streakCalls === [])
		{
			return '';
		}

		usort(
			$streakCalls,
			$situation === Situation::GoodStreak
				? static fn (array $a, array $b): int => $b['assessment'] <=> $a['assessment']
				: static fn (array $a, array $b): int => $a['assessment'] <=> $b['assessment'],
		);

		$top = array_slice($streakCalls, 0, self::TOP_CALLS_LIMIT);

		$lines = [$this->loc($situation->getStreakCallsHeaderKey())];
		foreach ($top as $call)
		{
			$lines[] = $this->formatCallLine($call);
		}

		return implode("\n", $lines);
	}

	/**
	 * @param array{id:int, activityId:int, ownerTypeId:int, ownerId:int, jobId:int, assessment:int, subject:string} $call
	 */
	private function formatCallLine(array $call): string
	{
		$subject = SubjectSanitizer::sanitize(trim((string)($call['subject'] ?? '')));
		if ($subject === '')
		{
			$subject = (string)Loc::getMessage('CRM_COPILOT_SUMMARY_STREAK_CALL_FALLBACK_SUBJECT', [
				'#ID#' => (string)($call['activityId'] ?? 0),
			]);
		}

		$url = CallAssessmentDrawerUrl::build(
			$call['activityId'] ?? 0,
			$call['ownerTypeId'] ?? 0,
			$call['ownerId'] ?? 0,
			$call['jobId'] ?? 0,
		);
		if ($url !== '')
		{
			$subject = '[URL=' . $url . ']' . $subject . '[/URL]';
		}

		return (string)Loc::getMessage('CRM_COPILOT_SUMMARY_STREAK_CALL_LINE', [
			'#SUBJECT#' => $subject,
		]);
	}

}
