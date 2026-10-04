<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Dictionary;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Dictionary\AddressTypeDto;
use Bitrix\Crm\V2\Public\Entity\Item\AddressType;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\Mapping\Mapper;

final class AddressTypeDtoMapper extends Mapper
{
	private const FIELDS = ['id', 'name', 'title'];

	/**
	 * @param list<AddressType> $items
	 */
	public function mapCollection(array $items, array $fields = []): DtoCollection
	{
		$collection = new DtoCollection(AddressTypeDto::class);

		foreach ($items as $item)
		{
			$collection->add($this->mapItem($item, $fields));
		}

		return $collection;
	}

	private function mapItem(AddressType $item, array $selectedFields): AddressTypeDto
	{
		$dto = new AddressTypeDto();
		$allFieldsSelected = $selectedFields === [];

		foreach (self::FIELDS as $field)
		{
			if ($allFieldsSelected || in_array($field, $selectedFields, true))
			{
				$dto->{$field} = match ($field)
				{
					'id' => $item->getId(),
					'name' => $item->getName(),
					'title' => $item->getTitle(),
				};
			}
		}

		return $dto;
	}
}
