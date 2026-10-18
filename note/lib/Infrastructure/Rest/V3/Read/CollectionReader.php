<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Read;

use Bitrix\Note\Internal\Access\PortalAdmin;
use Bitrix\Note\Internal\Access\Service\CollectionAccessService;
use Bitrix\Note\Internal\Model\Collection;
use Bitrix\Note\Internal\Repository\CollectionRepository;

/**
 * Reads collections for REST.
 *
 * The screen provider answers with more than a list - access levels, policy, the flags the sidebar
 * draws itself from. REST asks for entities and gets entities; everything else it needs it asks for
 * by name.
 */
class CollectionReader
{
	public function __construct(
		private readonly CollectionRepository $collections = new CollectionRepository(),
	) {}

	/**
	 * @return int[]|null null when the caller is not restricted, [] when nothing is visible
	 */
	public function visibleTo(int $userId): ?array
	{
		if (PortalAdmin::isAdmin($userId))
		{
			return null;
		}

		$levels = CollectionAccessService::getAllUserLevels(CollectionAccessService::buildUserAccessCodes($userId));

		$ids = [];
		foreach ($levels['effective'] ?? [] as $collectionId => $level)
		{
			if ((int)$level >= CollectionAccessService::LEVEL_VIEW)
			{
				$ids[] = (int)$collectionId;
			}
		}

		return $ids;
	}

	public function list(CollectionReadQuery $query): ReadResult
	{
		if ($query->visibleIds === [])
		{
			return new ReadResult();
		}

		$rows = $query->visibleIds === null
			? $this->collections->getList($query->fetchLimit(), $query->afterPosition(), $query->afterId())
			: $this->collections->getListByIds(
				$query->visibleIds,
				$query->fetchLimit(),
				$query->afterPosition(),
				$query->afterId(),
			);

		return ReadResult::paginate(
			$rows,
			$query->limit,
			static fn(Collection $collection): array => [
				'position' => (int)$collection->getPosition(),
				'id' => (int)$collection->getId(),
			],
		);
	}
}
