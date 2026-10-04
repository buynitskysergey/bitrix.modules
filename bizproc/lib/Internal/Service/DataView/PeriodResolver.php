<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\DataView;

use Bitrix\Bizproc\Internal\Exception\DataView\InvalidDataViewDefinitionException;
use Bitrix\Main\Type\DateTime;

final class PeriodResolver
{
	/**
	 * @return array{from: DateTime, to: DateTime}
	 * @throws InvalidDataViewDefinitionException
	 */
	public function resolve(array $period): array
	{
		$mode = $period['mode'] ?? null;

		return match ($mode)
		{
			'calendar' => $this->resolveCalendar($period),
			'current' => $this->monthWindow($this->currentYear(), $this->currentMonth()),
			'previous' => $this->monthWindow($this->currentYear(), $this->currentMonth() - 1),
			default => throw new InvalidDataViewDefinitionException(
				sprintf(
					'Unsupported DataView period mode "%s".',
					is_scalar($mode) ? (string)$mode : gettype($mode),
				),
				violation: InvalidDataViewDefinitionException::VIOLATION_PERIOD,
			),
		};
	}

	/**
	 * @return array{from: DateTime, to: DateTime}
	 * @throws InvalidDataViewDefinitionException
	 */
	private function resolveCalendar(array $period): array
	{
		$month = $period['month'] ?? null;
		if (!is_string($month) || !preg_match('/^(\d{4})-(\d{2})$/', $month, $m))
		{
			throw new InvalidDataViewDefinitionException(
				'DataView calendar period requires "month" in "YYYY-MM" format.',
				violation: InvalidDataViewDefinitionException::VIOLATION_PERIOD,
			);
		}

		$monthNum = (int)$m[2];
		if ($monthNum < 1 || $monthNum > 12)
		{
			throw new InvalidDataViewDefinitionException(
				sprintf(
					'DataView calendar period month "%s" is out of range.',
					$month,
				),
				violation: InvalidDataViewDefinitionException::VIOLATION_PERIOD,
			);
		}

		return $this->monthWindow((int)$m[1], $monthNum);
	}

	private function monthWindow(int $year, int $month): array
	{
		return [
			'from' => DateTime::createFromTimestamp(mktime(0, 0, 0, $month, 1, $year)),
			'to' => DateTime::createFromTimestamp(mktime(0, 0, 0, $month + 1, 1, $year)),
		];
	}

	private function currentYear(): int
	{
		return (int)date('Y');
	}

	private function currentMonth(): int
	{
		return (int)date('n');
	}
}
