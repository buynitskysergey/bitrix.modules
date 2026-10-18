<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Dto\Mapping;

use Bitrix\Note\Infrastructure\Rest\V3\Dto\CollectionItemDto;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Note\Internal\Access\PortalAdmin;
use Bitrix\Note\Internal\Access\Service\CollectionAccessService;
use Bitrix\Note\Internal\Access\Service\CollectionPolicyResolver;
use Bitrix\Note\Internal\Model\Collection;
use Bitrix\Note\Internal\Service\Document\MainDocumentService;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\Mapping\Mapper;

class CollectionMapper extends Mapper
{
	use SelectsFields;

	private const FIELDS = [
		'id',
		'name',
		'position',
		'policyLevel',
		'accessLevel',
		'isArchived',
		'createdBy',
		'createdAt',
		'updatedBy',
		'updatedAt',
		'markdownDescription',
	];

	/**
	 * @param Collection[] $items
	 */
	public function mapCollection(array $items, array $fields = []): DtoCollection
	{
		// The description is the main document's text - the one field here worth a query of its own, and
		// the one that is skipped entirely when the caller did not ask for it.
		// Single batch load, reused for every item below. The base Mapper routes mapOne() through
		// mapCollection(), so get/add/update also pass here.
		$descriptions = $this->isFieldSelected('markdownDescription', $fields)
			? $this->loadDescriptions($items)
			: [];

		// One batch for the whole page: the policy of a collection lives in its access rows, and asking
		// per item would turn a page into as many queries as it has collections.
		$policies = $this->isFieldSelected('policyLevel', $fields)
			? (new CollectionPolicyResolver())->resolveBatch($this->idsOf($items))
			: [];

		$accessLevels = $this->isFieldSelected('accessLevel', $fields)
			? $this->loadAccessLevels($this->idsOf($items))
			: [];

		$collection = new DtoCollection(CollectionItemDto::class);
		foreach ($items as $item)
		{
			$collection->add($this->mapEntity($item, $descriptions, $policies, $accessLevels, $fields));
		}

		return $collection;
	}

	/**
	 * @param array<int, string> $descriptions raw MARKDOWN keyed by collectionId
	 * @param array<int, string> $policies policy code keyed by collectionId
	 * @param array<int, string> $accessLevels access code of the current user keyed by collectionId
	 * @param string[]|array<string, mixed> $fields
	 */
	private function mapEntity(
		Collection $entity,
		array $descriptions,
		array $policies,
		array $accessLevels,
		array $fields,
	): CollectionItemDto
	{
		$dto = new CollectionItemDto();
		foreach (self::FIELDS as $field)
		{
			if (!$this->isFieldSelected($field, $fields))
			{
				continue;
			}

			$dto->{$field} = match ($field)
			{
				'id' => (int)$entity->getId(),
				'name' => (string)$entity->getName(),
				'position' => (int)$entity->getPosition(),
				// Never from the POLICY_LEVEL column of the row: nothing writes to it.
				'policyLevel' => $policies[(int)$entity->getId()] ?? CollectionPolicyResolver::POLICY_PRIVATE,
				// A collection the caller cannot see never reaches the mapper, so the floor here is view.
				'accessLevel' => $accessLevels[(int)$entity->getId()] ?? CollectionAccessService::ACCESS_CODE_VIEW,
				'isArchived' => (bool)$entity->getIsArchived(),
				'createdBy' => (int)$entity->getCreatedBy(),
				'createdAt' => $entity->getCreatedAt(),
				'updatedBy' => (int)$entity->getUpdatedBy(),
				'updatedAt' => $entity->getUpdatedAt(),
				// Empty or absent main-document markdown maps to null, keeping the field consistent
				// with the SQL HAS_DESCRIPTION flag (MARKDOWN IS NOT NULL AND MARKDOWN <> '').
				'markdownDescription' => ($descriptions[(int)$entity->getId()] ?? '') === ''
					? null
					: $descriptions[(int)$entity->getId()],
			};
		}

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

		return (new MainDocumentService())->getMarkdownByCollectionIds($this->idsOf($items));
	}

	/**
	 * What the current user may do with each of these collections, in one batch.
	 *
	 * @param int[] $collectionIds
	 * @return array<int, string> access code keyed by collectionId
	 */
	private function loadAccessLevels(array $collectionIds): array
	{
		if (empty($collectionIds))
		{
			return [];
		}

		$userId = (int)CurrentUser::get()->getId();
		if (PortalAdmin::isAdmin($userId))
		{
			// The administrator owns everything on the portal; asking the access table would only confirm it.
			return array_fill_keys($collectionIds, CollectionAccessService::ACCESS_CODE_FULL);
		}

		// '*' is added to the codes on purpose: a user with no row of their own inherits the level the
		// collection grants to everybody, and without that row the batch would call them a stranger.
		$codes = [...CollectionAccessService::buildUserAccessCodes($userId), '*'];
		$levels = CollectionAccessService::batchGetUserLevels($collectionIds, $codes);

		$result = [];
		foreach ($collectionIds as $collectionId)
		{
			$result[$collectionId] = CollectionAccessService::levelToAccessCode(
				$levels[$collectionId] ?? CollectionAccessService::LEVEL_NONE,
			);
		}

		return $result;
	}

	/**
	 * @param Collection[] $items
	 * @return int[]
	 */
	private function idsOf(array $items): array
	{
		return array_map(static fn(Collection $item): int => (int)$item->getId(), $items);
	}
}
