<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\DataView\Dto;

use Bitrix\Main\Type\DateTime;

final class AggregateQuery
{
	/** @var string[] */
	public readonly array $groupBy;

	/** @var AggregateFunctionCall[] */
	public readonly array $functions;

	/** @var array{from: DateTime, to: DateTime}|null */
	public readonly ?array $dateWindow;

	/**
	 * @param string[] $groupBy
	 * @param AggregateFunctionCall[] $functions
	 * @param array{from: DateTime, to: DateTime}|null $dateWindow
	 */
	public function __construct(
		array $groupBy,
		array $functions,
		public readonly int $limit,
		?array $dateWindow = null,
	) {
		if ($groupBy === [])
		{
			throw new \InvalidArgumentException('AggregateQuery.groupBy must not be empty');
		}

		if ($functions === [])
		{
			throw new \InvalidArgumentException('AggregateQuery.functions must not be empty');
		}

		if ($limit < 1)
		{
			throw new \InvalidArgumentException('AggregateQuery.limit must be positive');
		}

		$this->groupBy = array_values($groupBy);
		$this->functions = array_values($functions);
		$this->dateWindow = $dateWindow === null ? null : self::normalizeDateWindow($dateWindow);
	}

	public function hasDateWindow(): bool
	{
		return $this->dateWindow !== null;
	}

	public function getDateFrom(): ?DateTime
	{
		return $this->dateWindow['from'] ?? null;
	}

	public function getDateTo(): ?DateTime
	{
		return $this->dateWindow['to'] ?? null;
	}

	/**
	 * @return string[]
	 */
	public function getUsedColumns(): array
	{
		$columns = $this->groupBy;
		foreach ($this->functions as $function)
		{
			$columns[] = $function->column;
		}

		return array_values(array_unique($columns));
	}

	/**
	 * @param array $dateWindow
	 * @return array{from: DateTime, to: DateTime}
	 */
	private static function normalizeDateWindow(array $dateWindow): array
	{
		$from = $dateWindow['from'] ?? null;
		$to = $dateWindow['to'] ?? null;
		if (!($from instanceof DateTime) || !($to instanceof DateTime))
		{
			throw new \InvalidArgumentException(
				'AggregateQuery.dateWindow must be ["from" => DateTime, "to" => DateTime]'
			);
		}

		return ['from' => $from, 'to' => $to];
	}
}
