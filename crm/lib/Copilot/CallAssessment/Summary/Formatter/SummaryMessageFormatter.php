<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\CallAssessment\Summary\Formatter;

use Bitrix\Crm\Copilot\CallAssessment\Summary\CallAssessmentDrawerUrl;
use Bitrix\Main\Context;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\Date;

final class SummaryMessageFormatter
{
	private const FALLBACK_DAY_MONTH_FORMAT = 'j F';

	/**
	 * @param array{
	 *     weekStart: Date,
	 *     weekEnd: Date,
	 *     totalCount: int,
	 *     avgAssessment: int,
	 *     topGood: list<array{activityId:int, subject:string, assessment:int, ownerTypeId:int, ownerId:int, jobId:int}>,
	 *     topBad: list<array{activityId:int, subject:string, assessment:int, ownerTypeId:int, ownerId:int, jobId:int}>,
	 * } $summary
	 */
	public function format(array $summary): string
	{
		$lines = [
			Loc::getMessage('CRM_COPILOT_SUMMARY_AGENT_HEADER', [
				'#RANGE#' => $this->formatRange($summary['weekStart'], $summary['weekEnd']),
			]),
			'',
		];

		$totalCount = (int)$summary['totalCount'];
		if ($totalCount === 0)
		{
			$lines[] = Loc::getMessage('CRM_COPILOT_SUMMARY_AGENT_EMPTY_LINE');

			return implode("\n", $lines);
		}

		$lines[] = $this->formatTotalLine($totalCount, (int)$summary['avgAssessment']);
		$lines[] = '';

		$topGood = $summary['topGood'] ?? [];
		$topBad = $summary['topBad'] ?? [];

		if ($topBad === [])
		{
			foreach ($topGood as $call)
			{
				$lines[] = $this->formatItem($call);
			}
		}
		else
		{
			$lines[] = Loc::getMessage('CRM_COPILOT_SUMMARY_AGENT_TOP_GOOD_HEADER');
			foreach ($topGood as $call)
			{
				$lines[] = $this->formatItem($call);
			}

			$lines[] = '';
			$lines[] = Loc::getMessage('CRM_COPILOT_SUMMARY_AGENT_TOP_BAD_HEADER');
			foreach ($topBad as $call)
			{
				$lines[] = $this->formatItem($call);
			}
		}

		return implode("\n", $lines);
	}

	private function formatRange(Date $start, Date $end): string
	{
		$format = Context::getCurrent()?->getCulture()?->getDayMonthFormat()
			?? self::FALLBACK_DAY_MONTH_FORMAT
		;

		return Loc::getMessage('CRM_COPILOT_SUMMARY_AGENT_RANGE', [
			'#START#' => \FormatDate($format, $start->getTimestamp()),
			'#END#' => \FormatDate($format, $end->getTimestamp()),
		]);
	}

	private function formatTotalLine(int $count, int $avg): string
	{
		return Loc::getMessage('CRM_COPILOT_SUMMARY_AGENT_TOTAL_LINE', [
			'#COUNT#' => $count,
			'#NOUN#' => Loc::getMessagePlural('CRM_COPILOT_SUMMARY_AGENT_CALLS_NOUN', $count),
			'#AVG#' => $avg,
		]);
	}

	/**
	 * @param array{activityId:int, subject:string, assessment:int, ownerTypeId:int, ownerId:int, jobId:int} $call
	 */
	private function formatItem(array $call): string
	{
		$subject = SubjectSanitizer::sanitize(trim((string)($call['subject'] ?? '')));
		if ($subject === '')
		{
			$subject = Loc::getMessage('CRM_COPILOT_SUMMARY_AGENT_CALL_FALLBACK_SUBJECT', [
				'#ID#' => (string)($call['activityId'] ?? 0),
			]);
		}

		$callQualityUrl = CallAssessmentDrawerUrl::build(
			$call['activityId'] ?? 0,
			$call['ownerTypeId'] ?? 0,
			$call['ownerId'] ?? 0,
			$call['jobId'] ?? 0,
		);
		if ($callQualityUrl !== '')
		{
			$subject = '[URL=' . $callQualityUrl . ']' . $subject . '[/URL]';
		}

		return Loc::getMessage('CRM_COPILOT_SUMMARY_AGENT_LIST_ITEM', [
			'#SUBJECT#' => $subject,
			'#ASSESSMENT#' => $call['assessment'] ?? 0,
		]);
	}
}
