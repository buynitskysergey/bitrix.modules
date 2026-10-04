<?php

namespace Bitrix\Main\Infrastructure\Rest\Dto\Mapping;

use Bitrix\Main\Infrastructure\Rest\Dto\SmileDto;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\Mapping\Mapper;

class SmileMapper extends Mapper
{
	private static array $itemFieldMapping = [
		'id' => 'id',
		'setId' => 'setId',
		'name' => 'name',
		'image' => 'image',
		'typing' => 'typing',
		'width' => 'width',
		'height' => 'height',
		'definition' => 'definition',
	];

	public function mapCollection(array $items, array $fields = []): DtoCollection
	{
		$collection = new DtoCollection(SmileDto::class);

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

	private function mapItem(array $rawItem, array $fields): SmileDto
	{
		$dto = new SmileDto();
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
			'id', 'setId', 'width', 'height' => $value !== null ? (int)$value : null,
			default => $value,
		};
	}
}
