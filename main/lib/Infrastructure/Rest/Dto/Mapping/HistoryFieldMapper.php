<?php

namespace Bitrix\Main\Infrastructure\Rest\Dto\Mapping;

use Bitrix\Main\Infrastructure\Rest\Dto\HistoryFieldDto;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\Mapping\Mapper;

class HistoryFieldMapper extends Mapper
{
	private static array $itemFieldMapping = [
		'id' => 'ID',
		'historyId' => 'HISTORY_ID',
		'field' => 'FIELD',
		'data' => 'DATA',
	];

	public function mapCollection(array $items, array $fields = []): DtoCollection
	{
		$collection = new DtoCollection(HistoryFieldDto::class);

		foreach ($items as $rawItem)
		{
			if (!is_array($rawItem))
			{
				continue;
			}

			$collection->add($this->mapHistoryFieldItem($rawItem, $fields));
		}

		return $collection;
	}

	private function mapHistoryFieldItem(array $rawItem, array $fields): HistoryFieldDto
	{
		$dto = new HistoryFieldDto();
		$emptyFields = empty($fields);

		foreach (self::$itemFieldMapping as $dtoField => $dataField)
		{
			if ($this->shouldMapField($dtoField, $fields, $emptyFields))
			{
				$dto->{$dtoField} = $this->mapItemValue($dtoField, $rawItem[$dataField] ?? null);
			}
		}

		return $dto;
	}

	private function shouldMapField(string $dtoField, array $fields, bool $emptyFields): bool
	{
		if ($emptyFields)
		{
			return true;
		}

		return in_array($dtoField, $fields, true) || array_key_exists($dtoField, $fields);
	}

	private function mapItemValue(string $dtoField, mixed $value): mixed
	{
		return match ($dtoField)
		{
			'id', 'historyId' => $value !== null ? (int)$value : null,
			default => $value,
		};
	}
}
