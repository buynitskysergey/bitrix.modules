<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Item;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\AddressDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\ItemIdentifierDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\MoneyDto;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Currency\UserField\Types\MoneyType;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\DtoField;

final class CustomFieldValueConverter
{
	public function convert(DtoField $field, mixed $value): mixed
	{
		if ($value === null)
		{
			return null;
		}

		$propertyType = $field->getElementType() ?? $field->getPropertyType();
		if (!is_subclass_of($propertyType, Dto::class))
		{
			return $value;
		}

		if ($field->isMultiple() || $value instanceof DtoCollection)
		{
			$result = [];
			if (is_iterable($value))
			{
				foreach ($value as $item)
				{
					$result[] = $this->convertElement($field, $item);
				}
			}

			return $result;
		}

		return $this->convertElement($field, $value);
	}

	public function convertElement(DtoField $field, mixed $value): mixed
	{
		if ($value === null)
		{
			return null;
		}

		$propertyType = $field->getElementType() ?? $field->getPropertyType();
		if (!is_subclass_of($propertyType, Dto::class))
		{
			return $value;
		}

		return match (true)
		{
			is_a($propertyType, MoneyDto::class, true) => $this->convertMoney($value),
			is_a($propertyType, AddressDto::class, true) => $this->convertAddress($value),
			is_a($propertyType, ItemIdentifierDto::class, true) => $this->convertItemIdentifier($value),
			default => $value,
		};
	}

	private function convertMoney(mixed $value): string
	{
		$value = (array)$value;

		return MoneyType::formatToDb(
			(string)($value['sum'] ?? 0.0),
			(string)($value['currencyId'] ?? ''),
		);
	}

	private function convertAddress(mixed $value): string
	{
		$value = (array)$value;

		return sprintf(
			'%s|%s;%s',
			$value['address'] ?? '',
			$value['latitude'] ?? '',
			$value['longitude'] ?? '',
		);
	}

	private function convertItemIdentifier(mixed $value): string
	{
		$value = (array)$value;
		$entityType = EntityType::fromId((int)($value['entityTypeId'] ?? 0));

		return sprintf(
			'%s_%d',
			\CCrmOwnerTypeAbbr::ResolveByTypeID($entityType->getId()),
			$value['entityId'] ?? 0,
		);
	}
}
