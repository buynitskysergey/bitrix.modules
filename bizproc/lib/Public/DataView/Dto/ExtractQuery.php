<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\DataView\Dto;

use Bitrix\Main\Type\DateTime;

final class ExtractQuery
{
	/** @var array{from: DateTime, to: DateTime}|null */
	public readonly ?array $dateWindow;

	/** @var array{field: string, values: array}|null */
	public readonly ?array $keyIn;

	/**
	 * @param string[] $select
	 * @param array{from: DateTime, to: DateTime}|null $dateWindow
	 * @param array{field: string, values: array}|null $keyIn
	 */
	public function __construct(
		public readonly int $limit,
		public readonly array $select = [],
		?array $dateWindow = null,
		?array $keyIn = null,
	) {
		if ($limit < 0)
		{
			throw new \InvalidArgumentException('ExtractQuery.limit must be non-negative');
		}

		$this->dateWindow = $dateWindow === null ? null : self::normalizeDateWindow($dateWindow);
		$this->keyIn = $keyIn === null ? null : self::normalizeKeyIn($keyIn);
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

	public function hasKeyIn(): bool
	{
		return $this->keyIn !== null;
	}

	public function getKeyField(): ?string
	{
		return $this->keyIn['field'] ?? null;
	}

	/** @return array */
	public function getKeyValues(): array
	{
		return $this->keyIn['values'] ?? [];
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
				'ExtractQuery.dateWindow must be ["from" => DateTime, "to" => DateTime]'
			);
		}

		return ['from' => $from, 'to' => $to];
	}

	/**
	 * @param array $keyIn
	 * @return array{field: string, values: array}
	 */
	private static function normalizeKeyIn(array $keyIn): array
	{
		$field = $keyIn['field'] ?? null;
		if (!is_string($field) || $field === '')
		{
			throw new \InvalidArgumentException('ExtractQuery.keyIn requires a non-empty "field"');
		}

		return ['field' => $field, 'values' => array_values((array)($keyIn['values'] ?? []))];
	}
}
