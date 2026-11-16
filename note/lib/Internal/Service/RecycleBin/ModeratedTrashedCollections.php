<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\RecycleBin;

use Bitrix\Note\Internal\Access\PortalAdmin;
use Bitrix\Note\Internal\Access\Service\CollectionAccessService;
use Bitrix\Note\Internal\Repository\CollectionRepository;

/**
 * Bases lying in the bin that one caller may act on as a whole: moderator level on the base, or
 * portal admin.
 *
 * One definition for both directions of the over-section operations - emptying the bin and
 * restoring everything from it. Kept apart from either command because the two must agree on what
 * "the bases of this reader's bin" means: a base the cleanup takes away has to be a base the
 * restore brings back, or the destructive half reaches further than the saving one.
 */
final class ModeratedTrashedCollections
{
	private const CHUNK_SIZE = 200;

	public function __construct(
		private readonly CollectionRepository $collectionRepository = new CollectionRepository(),
	) {}

	/**
	 * Yields them one by one, so the admin branch walks the trashed bases by keyset instead of
	 * holding every trashed id of the portal in memory to touch the first few of them.
	 *
	 * @param array<int, string> $accessCodes
	 * @return \Generator<int>
	 */
	public function iterate(int $userId, array $accessCodes): \Generator
	{
		if (PortalAdmin::isAdmin($userId))
		{
			$afterId = 0;
			do
			{
				$chunk = $this->collectionRepository->listTrashedIds($afterId, self::CHUNK_SIZE);
				foreach ($chunk as $collectionId)
				{
					$afterId = $collectionId;

					yield $collectionId;
				}
			}
			while (count($chunk) === self::CHUNK_SIZE);

			return;
		}

		// The ACL of a base is untouched by the bin, so the level map answers for trashed bases too.
		$levels = CollectionAccessService::getAllUserLevels($accessCodes)['effective'] ?? [];
		$moderated = [];
		foreach ($levels as $collectionId => $level)
		{
			if ((int)$level >= CollectionAccessService::LEVEL_MODERATE)
			{
				$moderated[] = (int)$collectionId;
			}
		}

		yield from $this->collectionRepository->filterTrashedIds($moderated);
	}
}
