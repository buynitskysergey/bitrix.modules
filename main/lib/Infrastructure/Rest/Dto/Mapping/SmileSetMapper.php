<?php

namespace Bitrix\Main\Infrastructure\Rest\Dto\Mapping;

use Bitrix\Main\Infrastructure\Rest\Dto\SmileSetDto;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\Mapping\Mapper;

class SmileSetMapper extends Mapper
{
	private static array $itemFieldMapping = [
		'id' => 'id',
		'parentId' => 'parentId',
		'name' => 'name',
		'type' => 'type',
	];

	public function mapCollection(array $items, array $fields = []): DtoCollection
	{
		$collection = new DtoCollection(SmileSetDto::class);

		foreach ($items as $rawItem)
		{
			if (!is_array($rawItem))
			{
				continue;
			}

			$collection->add($this->mapItem($rawItem, $fields));
		}

		return $collection;
	}

	private function mapItem(array $rawItem, array $fields): SmileSetDto
	{
		$dto = new SmileSetDto();
		$emptyFields = empty($fields);

		foreach (self::$itemFieldMapping as $dtoField => $dataField)
		{
			if (!$this->shouldMapField($dtoField, $fields, $emptyFields))
			{
				continue;
			}

			$dto->{$dtoField} = $this->mapValue($dtoField, $rawItem[$dataField] ?? null);
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

	private function mapValue(string $dtoField, mixed $value): mixed
	{
		return match ($dtoField)
		{
			'id', 'parentId' => $value !== null ? (int)$value : null,
			default => $value,
		};
	}
}
