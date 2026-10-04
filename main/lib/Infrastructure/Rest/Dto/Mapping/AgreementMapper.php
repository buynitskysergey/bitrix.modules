<?php

namespace Bitrix\Main\Infrastructure\Rest\Dto\Mapping;

use Bitrix\Main\Infrastructure\Rest\Dto\AgreementDto;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\Mapping\Mapper;

class AgreementMapper extends Mapper
{
	private static array $itemFieldMapping = [
		'id' => 'ID',
		'name' => 'NAME',
		'active' => 'ACTIVE',
		'languageId' => 'LANGUAGE_ID',
		'label' => 'LABEL',
		'text' => 'TEXT',
	];

	/**
	 * Request-only field: omitted when select is empty, returned only when explicitly requested.
	 */
	private static array $requestFieldMapping = [
		'replace' => 'REPLACE',
	];

	public function mapCollection(array $items, array $fields = []): DtoCollection
	{
		$collection = new DtoCollection(AgreementDto::class);

		foreach ($items as $rawItem)
		{
			if (!is_array($rawItem))
			{
				continue;
			}

			$collection->add($this->mapAgreementItem($rawItem, $fields));
		}

		return $collection;
	}

	private function mapAgreementItem(array $rawItem, array $fields): AgreementDto
	{
		$dto = new AgreementDto();
		$emptyFields = empty($fields);

		foreach (self::$itemFieldMapping as $dtoField => $dataField)
		{
			if (!$this->shouldMapField($dtoField, $fields, defaultIncluded: true, emptyFields: $emptyFields))
			{
				continue;
			}

			if ($emptyFields && !array_key_exists($dataField, $rawItem))
			{
				continue;
			}

			$dto->{$dtoField} = $this->mapValue($dtoField, $rawItem[$dataField] ?? null);
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

	private function mapValue(string $dtoField, mixed $value): mixed
	{
		return match ($dtoField)
		{
			'id' => $value !== null ? (int)$value : null,
			'active' => $value === null ? null : ($value === 'Y' || $value === true),
			default => $value,
		};
	}
}
