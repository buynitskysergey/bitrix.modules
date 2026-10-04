<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Market\Mapper;

use Bitrix\Mobile\Market\Dto\SortInfo;
use Bitrix\Mobile\Market\Dto\SortItem;

final class SortInfoMapper
{
	private const SORT_ID_FAVORITE = 'favorite';
	private const SORT_ID_RATING = 'rating';
	private const SORT_ID_INSTALLS = 'installs';
	private const SORT_ID_DATE = 'date';
	private const SORT_FIELD_ID_MAP = [
		'POPULARITY' => self::SORT_ID_FAVORITE,
		'DATE_PUBLIC' => self::SORT_ID_DATE,
		'RATING_SORT' => self::SORT_ID_RATING,
		'NUM_INSTALLS' => self::SORT_ID_INSTALLS,
	];

	public function map(mixed $sortInfo): ?SortInfo
	{
		if (!is_array($sortInfo))
		{
			return null;
		}

		$items = [];
		foreach (($sortInfo['LIST'] ?? []) as $item)
		{
			$preparedItem = $this->mapItem($item);
			if ($preparedItem !== null)
			{
				$items[] = $preparedItem;
			}
		}

		if (empty($items))
		{
			return null;
		}

		return new SortInfo(
			current: $this->mapItem($sortInfo['CURRENT'] ?? null) ?? $items[0],
			items: $items,
		);
	}

	private function mapItem(mixed $sortInfoItem): ?SortItem
	{
		if (!is_array($sortInfoItem))
		{
			return null;
		}

		$title = trim((string)($sortInfoItem['NAME'] ?? ''));
		$value = ($sortInfoItem['VALUE'] ?? null);
		if ($title === '' || !is_array($value))
		{
			return null;
		}

		return new SortItem(
			id: $this->resolveItemId($value),
			title: $title,
			value: $value,
		);
	}

	private function resolveItemId(array $value): string
	{
		foreach (array_keys($value) as $sortField)
		{
			$sortField = strtoupper((string)$sortField);

			if (isset(self::SORT_FIELD_ID_MAP[$sortField]))
			{
				return self::SORT_FIELD_ID_MAP[$sortField];
			}
		}

		return self::SORT_ID_FAVORITE;
	}
}
