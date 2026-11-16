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
		'state',
		'isArchived',
		'isTrashed',
		'isOrphan',
	];

	/**
	 * Every field of the answer but the named ones.
	 *
	 * A select decides the answer field by field, and a caller who names nothing is given the whole
	 * of it - which is right for one document and wrong for a page of them, where a single field can
	 * cost more than everything else together. Whoever answers with a page says here what it leaves
	 * out ({@see \Bitrix\Note\Infrastructure\Rest\V3\Read\DocumentListQuery::HEAVY_FIELDS}).
	 *
	 * @param string[] $withheld
	 * @return string[]
	 */
	public static function fieldsWithout(array $withheld): array
	{
		return array_values(array_diff(self::FIELDS, $withheld));
	}

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
