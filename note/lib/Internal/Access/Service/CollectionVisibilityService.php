<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Access\Service;

use Bitrix\Note\Internal\Access\PortalAdmin;
use Bitrix\Note\Internal\Repository\CollectionRepository;

/**
 * Which knowledge bases a caller may be shown, as one answer for every listing that asks.
 *
 * Two rights decide it and they are not the same right. A living base belongs to whoever may read
 * it; a base lying in the recycle bin belongs to whoever may moderate it, because the bin is
 * governed by the right to the base as a whole - a caller who could merely read it while it was
 * alive is told it is gone.
 *
 * The portal administrator is named by no list at all, and that is the reason this lives in one
 * place: the bypass used to be spelled out by each caller separately, and the copies drifted -
 * one listing answered the administrator with every base while another answered them with the
 * bases they happened to be granted.
 */
final class CollectionVisibilityService
{
	/**
	 * @param bool $includeTrashed whether the listing asks for the recycle bin as well
	 * @return int[]|null null when the caller is bound by no list, [] when nothing is visible
	 */
	public static function readableIds(int $userId, bool $includeTrashed = false): ?array
	{
		if (PortalAdmin::isAdmin($userId))
		{
			return null;
		}

		$levels = self::levelsOf($userId);
		$alive = DocumentAccessService::accessibleCollectionIds($levels);

		if (!$includeTrashed)
		{
			// A base in the bin answers the trashed slice and nothing else, so asking which of the
			// moderated ones lie there is a query the other slices do not need.
			return $alive;
		}

		return [...$alive, ...(new CollectionRepository())->filterTrashedIds(self::moderatedIds($levels))];
	}

	/**
	 * @return array<int|string, int|string> collectionId => effective level
	 */
	private static function levelsOf(int $userId): array
	{
		return CollectionAccessService::getAllUserLevels(
			CollectionAccessService::buildUserAccessCodes($userId),
		)['effective'] ?? [];
	}

	/**
	 * @param array<int|string, int|string> $levels
	 * @return int[]
	 */
	private static function moderatedIds(array $levels): array
	{
		$ids = [];
		foreach ($levels as $collectionId => $level)
		{
			if ((int)$level >= CollectionAccessService::LEVEL_MODERATE)
			{
				$ids[] = (int)$collectionId;
			}
		}

		return $ids;
	}
}
