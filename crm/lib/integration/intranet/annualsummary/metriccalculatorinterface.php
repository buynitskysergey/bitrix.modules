<?php

namespace Bitrix\Crm\Integration\Intranet\AnnualSummary;

use Bitrix\Main\Type\Date;

interface MetricCalculatorInterface
{
	/**
	 * @return array{createdForms: int, views: int, submissions: int, abandonedFills: int}
	 */
	public function calculate(Date $from, Date $to): array;
}
