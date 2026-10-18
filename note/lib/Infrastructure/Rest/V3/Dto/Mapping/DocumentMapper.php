<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Dto\Mapping;

use Bitrix\Note\Infrastructure\Rest\V3\Dto\DocumentItemDto;
use Bitrix\Note\Infrastructure\Rest\V3\Read\DocumentReadModel;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\Mapping\Mapper;

class DocumentMapper extends Mapper
{
	use SelectsFields;

	private const FIELDS = [
		'id',
		'collectionId',
		'parentId',
		'title',
		'markdown',
		'position',
		'createdBy',
		'updatedBy',
		'createdAt',
		'updatedAt',
		'contentUpdatedAt',
		'isArchived',
		'isTrashed',
		'isOrphan',
	];

	/**
	 * @param DocumentReadModel[] $items
	 */
	public function mapCollection(array $items, array $fields = []): DtoCollection
	{
		$collection = new DtoCollection(DocumentItemDto::class);
		foreach ($items as $item)
		{
			$collection->add($this->mapReadModel($item, $fields));
		}

		return $collection;
	}

	/**
	 * @param string[]|array<string, mixed> $fields
	 */
	private function mapReadModel(DocumentReadModel $view, array $fields): DocumentItemDto
	{
		$dto = new DocumentItemDto();
		foreach (self::FIELDS as $field)
		{
			if ($this->isFieldSelected($field, $fields))
			{
				$dto->{$field} = $view->{$field};
			}
		}

		return $dto;
	}
}
