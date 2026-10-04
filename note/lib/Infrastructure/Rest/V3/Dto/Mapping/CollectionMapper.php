<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Dto\Mapping;

use Bitrix\Main\Type\DateTime;
use Bitrix\Note\Infrastructure\Rest\V3\Dto\CollectionItemDto;
use Bitrix\Note\Internal\Access\Service\CollectionAccessService;
use Bitrix\Note\Internal\Model\Collection;
use Bitrix\Note\Internal\Service\Document\MainDocumentService;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\Mapping\Mapper;

class CollectionMapper extends Mapper
{
	/**
	 * @param Collection[] $items
	 */
	public function mapCollection(array $items, array $fields = []): DtoCollection
	{
		// Single batch load of the main-document markdown - reused for every item below.
		// The base Mapper routes mapOne() through mapCollection(), so get/add/update also pass here.
		$descriptions = $this->loadDescriptions($items);

		$collection = new DtoCollection(CollectionItemDto::class);
		foreach ($items as $item)
		{
			$collection->add($this->mapEntity($item, $descriptions));
		}

		return $collection;
	}

	/**
	 * @param array<int, string> $descriptions raw MARKDOWN keyed by collectionId
	 */
	private function mapEntity(Collection $entity, array $descriptions = []): CollectionItemDto
	{
		$dto = new CollectionItemDto();
		$dto->id = (int)$entity->getId();
		$dto->name = (string)$entity->getName();
		$dto->position = (int)$entity->getPosition();
		$dto->policyLevel = CollectionAccessService::levelToCode($entity->getPolicyLevel());
		$dto->createdBy = (int)$entity->getCreatedBy();
		$dto->createdAt = $this->formatUtc($entity->getCreatedAt());
		$dto->updatedBy = (int)$entity->getUpdatedBy();
		$dto->updatedAt = $this->formatUtc($entity->getUpdatedAt());
		// Empty or absent main-document markdown maps to null, keeping the field consistent
		// with the SQL HAS_DESCRIPTION flag (MARKDOWN IS NOT NULL AND MARKDOWN <> '').
		$markdown = $descriptions[(int)$entity->getId()] ?? '';
		$dto->markdownDescription = $markdown === '' ? null : $markdown;

		return $dto;
	}

	/**
	 * Batch-loads the main-document MARKDOWN for the mapped collections.
	 *
	 * @param Collection[] $items
	 * @return array<int, string> raw MARKDOWN keyed by collectionId
	 */
	private function loadDescriptions(array $items): array
	{
		if (empty($items))
		{
			return [];
		}

		$collectionIds = [];
		foreach ($items as $item)
		{
			$collectionIds[] = (int)$item->getId();
		}

		return (new MainDocumentService())->getMarkdownByCollectionIds($collectionIds);
	}

	// REST contract: datetime is always returned in UTC (ISO 8601 with Z).
	private function formatUtc(?DateTime $dateTime): ?string
	{
		return $dateTime === null
			? null
			: gmdate('Y-m-d\TH:i:s\Z', $dateTime->getTimestamp());
	}
}
