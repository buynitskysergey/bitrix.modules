<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Service;

use Bitrix\Main\Application;
use Bitrix\Main\Localization\Loc;
use Bitrix\Timeman\V2\Internal\Entity\FullReport\FullReport;

final class ReportPeriodPhraseFormatter
{
	public function formatForReport(FullReport $report): string
	{
		$dateFrom = $report->dateFrom ?? $report->reportDate;
		$dateTo = $report->dateTo ?? $report->reportDate;

		if ($dateFrom === null || $dateTo === null)
		{
			return '';
		}

		return $this->format((int)$dateFrom, (int)$dateTo);
	}

	public function format(int $dateFrom, int $dateTo): string
	{
		if ($dateFrom <= 0 || $dateTo <= 0)
		{
			return '';
		}

		$dayMonthFormat = $this->getDayMonthFormat();

		$dayMonthFrom = (string)\FormatDate($dayMonthFormat, $dateFrom);
		$dayMonthTo = (string)\FormatDate($dayMonthFormat, $dateTo);
		$yearFrom = (string)\FormatDate('Y', $dateFrom);
		$yearTo = (string)\FormatDate('Y', $dateTo);

		if ($dayMonthFrom === $dayMonthTo && $yearFrom === $yearTo)
		{
			return (string)Loc::getMessage(
				'TIMEMAN_REPORT_PERIOD_PHRASE_SINGLE',
				['#DATE#' => $dayMonthTo . ' ' . $yearTo],
			);
		}

		return (string)Loc::getMessage(
			'TIMEMAN_REPORT_PERIOD_PHRASE_RANGE',
			[
				'#FROM#' => $dayMonthFrom,
				'#TO#' => $dayMonthTo . ' ' . $yearTo,
			],
		);
	}

	private function getDayMonthFormat(): string
	{
		return Application::getInstance()->getContext()->getCulture()->getDayMonthFormat();
	}
}
