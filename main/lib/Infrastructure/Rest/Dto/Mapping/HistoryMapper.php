<?php

namespace Bitrix\Main\Infrastructure\Rest\Dto\Mapping;

use Bitrix\Main\Infrastructure\Rest\Dto\HistoryDto;
use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\Mapping\Mapper;

class HistoryMapper extends Mapper
{
	private static array $itemFieldMapping = [
		'id' => 'ID',
		'userId' => 'USER_ID',
		'eventType' => 'EVENT_TYPE',
		'dateInsert' => 'DATE_INSERT',
		'remoteAddr' => 'REMOTE_ADDR',
		'userAgent' => 'USER_AGENT',
		'requestUri' => 'REQUEST_URI',
		'updatedById' => 'UPDATED_BY_ID',
	];

	/**
	 * Filter-only field: omitted when select is empty, returned only when explicitly requested.
	 */
	private static array $requestFieldMapping = [
		'field' => 'FIELD',
	];

	public function mapCollection(array $items, array $fields = []): DtoCollection
	{
		$collection = new DtoCollection(HistoryDto::class);

		foreach ($items as $rawItem)
		{
			if (!is_array($rawItem))
			{
				continue;
			}

			$collection->add($this->mapHistoryItem($rawItem, $fields));
		}

		return $collection;
	}

	private function mapHistoryItem(array $rawItem, array $fields): HistoryDto
	{
		$dto = new HistoryDto();
		$emptyFields = empty($fields);

		foreach (self::$itemFieldMapping as $dtoField => $dataField)
		{
			if ($this->shouldMapField($dtoField, $fields, defaultIncluded: true, emptyFields: $emptyFields))
			{
				$dto->{$dtoField} = $this->mapItemValue($dtoField, $rawItem[$dataField] ?? null);
			}
		}

		foreach (self::$requestFieldMapping as $dtoField => $dataField)
		{
			if ($this->shouldMapField($dtoField, $fields, defaultIncluded: false, emptyFields: $emptyFields))
			{
				$dto->{$dtoField} = $rawItem[$dataField] ?? null;
			}
		}

		return $dto;
	}

	private function shouldMapField(
		string $dtoField,
		array $fields,
		bool $defaultIncluded,
		bool $emptyFields,
	): bool
	{
		if ($emptyFields)
		{
			return $defaultIncluded;
		}

		return in_array($dtoField, $fields, true) || array_key_exists($dtoField, $fields);
	}

	private function mapItemValue(string $dtoField, mixed $value): mixed
	{
		return match ($dtoField)
		{
			'id', 'userId', 'eventType', 'updatedById' => $value !== null ? (int)$value : null,
			'dateInsert' => $this->mapDateTime($value),
			default => $value,
		};
	}

	private function mapDateTime(mixed $value): ?DateTime
	{
		if ($value instanceof DateTime)
		{
			return $value;
		}

		if (!is_string($value) || $value === '')
		{
			return null;
		}

		return DateTime::createFromUserTime(\CRestUtil::unConvertDateTime($value));
	}
}
