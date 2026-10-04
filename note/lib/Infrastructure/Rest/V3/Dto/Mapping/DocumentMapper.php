<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Dto\Mapping;

use Bitrix\Note\Infrastructure\Rest\V3\Dto\DocumentItemDto;
use Bitrix\Note\Public\Provider\Dto\DocumentReadDto;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\Mapping\Mapper;

class DocumentMapper extends Mapper
{
	/**
	 * @param DocumentReadDto[] $items
	 */
	public function mapCollection(array $items, array $fields = []): DtoCollection
	{
		$collection = new DtoCollection(DocumentItemDto::class);
		foreach ($items as $item)
		{
			$collection->add($this->mapReadModel($item));
		}

		return $collection;
	}

	private function mapReadModel(DocumentReadDto $view): DocumentItemDto
	{
		// Dates already arrive in UTC (ISO 8601 with Z) from DocumentProvider::getForRead().
		$dto = new DocumentItemDto();
		$dto->id = $view->id;
		$dto->collectionId = $view->collectionId;
		$dto->parentId = $view->parentId;
		$dto->title = $view->title;
		$dto->markdown = $view->markdown;
		$dto->position = $view->position;
		$dto->createdBy = $view->createdBy;
		$dto->updatedBy = $view->updatedBy;
		$dto->createdAt = $view->createdAt;
		$dto->updatedAt = $view->updatedAt;
		$dto->contentUpdatedAt = $view->contentUpdatedAt;

		return $dto;
	}
}
