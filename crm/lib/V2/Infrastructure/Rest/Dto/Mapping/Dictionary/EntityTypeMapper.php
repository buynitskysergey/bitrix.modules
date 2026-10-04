<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Dictionary;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Dictionary\EntityTypeDto;
use Bitrix\Crm\V2\Public\Entity\Dictionary\EntityType;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\Mapping\Mapper;

final class EntityTypeMapper extends Mapper
{
	private const FIELDS = ['id', 'code', 'title'];

	/**
	 * @param EntityType[] $items
	 */
	public function mapCollection(array $items, array $fields = []): DtoCollection
	{
		$collection = new DtoCollection(EntityTypeDto::class);

		foreach ($items as $item)
		{
			$collection->add($this->mapItem($item, $fields));
		}

		return $collection;
	}

	private function mapItem(EntityType $item, array $selectedFields): EntityTypeDto
	{
		$dto = new EntityTypeDto();
		$allFieldsSelected = $selectedFields === [];

		foreach (self::FIELDS as $field)
		{
			if ($allFieldsSelected || in_array($field, $selectedFields, true))
			{
				$dto->{$field} = match ($field)
				{
					'id' => $item->getId(),
					'code' => $item->getCode(),
					'title' => $item->getTitle(),
				};
			}
		}

		return $dto;
	}
}
