<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Dto\Mapping;

use Bitrix\Note\Infrastructure\Rest\V3\Dto\SearchResultItemDto;
use Bitrix\Note\Internal\Entity\Search\SearchResult;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\Mapping\Mapper;

class SearchResultMapper extends Mapper
{
	use SelectsFields;

	private const FIELDS = ['documentId', 'title', 'score', 'snippet', 'sharedAccess', 'collectionId'];

	/**
	 * @param SearchResult[] $items
	 */
	public function mapCollection(array $items, array $fields = []): DtoCollection
	{
		$collection = new DtoCollection(SearchResultItemDto::class);
		foreach ($items as $item)
		{
			$collection->add($this->mapResult($item, $fields));
		}

		return $collection;
	}

	/**
	 * @param string[]|array<string, mixed> $fields
	 */
	private function mapResult(SearchResult $result, array $fields): SearchResultItemDto
	{
		$dto = new SearchResultItemDto();
		foreach (self::FIELDS as $field)
		{
			if (!$this->isFieldSelected($field, $fields))
			{
				continue;
			}

			$dto->{$field} = match ($field)
			{
				'documentId' => $result->getDocumentId(),
				'title' => $result->getTitle(),
				'score' => $result->getScore(),
				'snippet' => $result->getSnippet(),
				'sharedAccess' => $result->isSharedAccess(),
				// Shared results never disclose the owning collection.
				'collectionId' => $result->isSharedAccess() ? null : $result->getCollectionId(),
			};
		}

		return $dto;
	}
}
