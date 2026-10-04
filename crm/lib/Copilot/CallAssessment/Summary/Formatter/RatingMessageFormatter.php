<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\CallAssessment\Summary\Formatter;

use Bitrix\Crm\Copilot\CallAssessment\Summary\CallAssessmentDrawerUrl;
use Bitrix\Crm\Copilot\CallAssessment\Summary\Situation;
use Bitrix\Main\Localization\Loc;

final class RatingMessageFormatter
{
	public const TOP_CALLS_LIMIT = 3;

	/**
	 * @param list<array{id:int, activityId:int, ownerTypeId:int, ownerId:int, jobId:int, assessment:int, subject:string}> $recentCalls
	 */
	public function formatForRecipients(
		Situation $situation,
		string $managerName,
		int $currentRating,
		int $thresholdValue,
		array $recentCalls = [],
	): string
	{
		return $this->compose(
			$this->loc($situation->getRatingHeaderKey(), ['#NAME#' => $managerName]),
			$situation,
			$currentRating,
			$thresholdValue,
			$recentCalls,
		);
	}

	/**
	 * @param list<array{id:int, activityId:int, ownerTypeId:int, ownerId:int, jobId:int, assessment:int, subject:string}> $recentCalls
	 */
	public function formatForManager(
		Situation $situation,
		int $currentRating,
		int $thresholdValue,
		array $recentCalls = [],
	): string
	{
		return $this->compose(
			$this->loc($situation->getRatingSelfHeaderKey()),
			$situation,
			$currentRating,
			$thresholdValue,
			$recentCalls,
		);
	}

	/**
	 * @param list<array{id:int, activityId:int, ownerTypeId:int, ownerId:int, jobId:int, assessment:int, subject:string}> $recentCalls
	 */
	private function compose(
		string $header,
		Situation $situation,
		int $currentRating,
		int $thresholdValue,
		array $recentCalls,
	): string
	{
		$blocks = array_filter([
			$header,
			$this->loc($situation->getRatingStatsKey(), [
				'#RATING#' => (string)$currentRating,
				'#THRESHOLD#' => (string)$thresholdValue,
			]),
			$this->formatTopCalls($situation, $recentCalls),
			$this->loc($situation->getRatingFooterKey()),
		], static fn(string $block): bool => $block !== '');

		return implode("\n\n", $blocks);
	}

	/**
	 * @param list<array{id:int, activityId:int, ownerTypeId:int, ownerId:int, jobId:int, assessment:int, subject:string}> $recentCalls
	 */
	private function formatTopCalls(Situation $situation, array $recentCalls): string
	{
		if ($recentCalls === [])
		{
			return '';
		}

		$lines = [$this->loc($situation->getRatingCallsHeaderKey())];
		foreach ($recentCalls as $call)
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
			$subject = $this->loc('CRM_COPILOT_SUMMARY_RATING_CALL_FALLBACK_SUBJECT', [
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

		return $this->loc('CRM_COPILOT_SUMMARY_RATING_CALL_LINE', [
			'#SUBJECT#' => $subject,
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
}
