<?php

namespace Bitrix\Crm\Integration\Intranet\AnnualSummary;

use Bitrix\Crm\WebForm\Internals\FormCounterDailyTable;
use Bitrix\Crm\WebForm\Internals\FormTable;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\Type\Date;

final class WebFormMetricCalculator implements MetricCalculatorInterface
{
	public function calculate(Date $from, Date $to): array
	{
		$createdForms = $this->countCreatedForms($from, $to);
		$counters = $this->calculateCounters($from, $to);
		$views = (int)($counters['VIEWS_TOTAL'] ?? 0);
		$startFills = (int)($counters['START_FILL_TOTAL'] ?? 0);
		$submissions = (int)($counters['END_FILL_TOTAL'] ?? 0);

		return [
			'createdForms' => $createdForms,
			'views' => $views,
			'submissions' => $submissions,
			'abandonedFills' => max(0, $startFills - $submissions),
		];
	}

	private function countCreatedForms(Date $from, Date $to): int
	{
		$row = FormTable::query()
			->addSelect(new ExpressionField('CREATED_FORMS_TOTAL', 'COUNT(%s)', 'ID'))
			->where('TYPE_ID', FormTable::TYPE_DEFAULT)
			->where('DATE_CREATE', '>=', $from)
			->where('DATE_CREATE', '<', $to)
			->fetch()
		;

		return (int)($row['CREATED_FORMS_TOTAL'] ?? 0);
	}

	private function calculateCounters(Date $from, Date $to): array
	{
		return FormCounterDailyTable::query()
			->registerRuntimeField(
				new Reference(
					'FORM',
					FormTable::class,
					Join::on('this.FORM_ID', 'ref.ID'),
					['join_type' => Join::TYPE_INNER],
				),
			)
			->addSelect(new ExpressionField('VIEWS_TOTAL', 'SUM(%s)', 'VIEWS'))
			->addSelect(new ExpressionField('START_FILL_TOTAL', 'SUM(%s)', 'START_FILL'))
			->addSelect(new ExpressionField('END_FILL_TOTAL', 'SUM(%s)', 'END_FILL'))
			->where('FORM.TYPE_ID', FormTable::TYPE_DEFAULT)
			->where('DATE_STAT', '>=', $from)
			->where('DATE_STAT', '<', $to)
			->fetch()
		;
	}
}
