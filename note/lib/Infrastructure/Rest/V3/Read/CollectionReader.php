<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Read;

use Bitrix\Note\Infrastructure\Rest\V3\Access\EntityState;
use Bitrix\Note\Internal\Access\Service\CollectionVisibilityService;
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
	 * Which bases this listing may show, asked of the one place that knows
	 * ({@see CollectionVisibilityService}). The states are the only part of the question that is the
	 * reader's own: the recycle bin is a slice the client asks for by name.
	 *
	 * @param EntityState[] $states states the listing is asked for
	 * @return int[]|null null when the caller is not restricted, [] when nothing is visible
	 */
	public function visibleTo(int $userId, array $states = []): ?array
	{
		return CollectionVisibilityService::readableIds($userId, in_array(EntityState::Trashed, $states, true));
	}

	public function list(CollectionReadQuery $query): ReadResult
	{
		if ($query->visibleIds === [])
		{
			return new ReadResult();
		}

		$rows = $query->visibleIds === null
			? $this->collections->getList(
				$query->fetchLimit(),
				$query->afterPosition(),
				$query->afterId(),
				$query->stateValues(),
			)
			: $this->collections->getListByIds(
				$query->visibleIds,
				$query->fetchLimit(),
				$query->afterPosition(),
				$query->afterId(),
				$query->stateValues(),
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
