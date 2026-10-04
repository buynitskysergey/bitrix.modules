<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Provider;

use Bitrix\Note\Internal\Access\Service\CollectionAccessService;
use Bitrix\Note\Internal\Service\Favorite\FavoriteListService;

/**
 * [P4.T2 / API-04 / DTO-01] Read side of the favorites block: one page of the caller's own list.
 *
 * The page is personal, so the provider resolves the access codes of that very user and hands them
 * to the list service together with the pagination parameters; assembling the rows (titles, rights,
 * coverage, chevron) is the service's job and stays batched there.
 */
final class FavoriteProvider
{
	public function __construct(
		private readonly FavoriteListService $listService = new FavoriteListService(),
	) {}

	/**
	 * @param array{position: int, id: int}|null $afterCursor
	 * @return array{items: array<int, array<string, mixed>>, nextCursor: array{position: int, id: int}|null}
	 */
	public function getPage(
		int $userId,
		int $limit = FavoriteListService::DEFAULT_LIMIT,
		?array $afterCursor = null,
		bool $onlyNotified = false,
	): array
	{
		if ($userId <= 0)
		{
			return ['items' => [], 'nextCursor' => null];
		}

		// The refill budget does NOT bound the first window - it is checked on entering an iteration,
		// when nothing has been read yet - so the page size needs its own cap.
		$normalizedLimit = min(
			FavoriteListService::MAX_LIMIT,
			$limit > 0 ? $limit : FavoriteListService::DEFAULT_LIMIT,
		);

		return $this->listService->listPage(
			$userId,
			CollectionAccessService::buildUserAccessCodes($userId),
			$normalizedLimit,
			$afterCursor,
			$onlyNotified,
		);
	}

	/**
	 * [DTO-01] One row of the caller's own list, of the same shape `getPage()` returns in `items`.
	 * `null` when there is no such favorite row or its object is not visible to that user.
	 *
	 * @return array<string, mixed>|null
	 */
	public function getRow(int $userId, string $entityType, int $entityId): ?array
	{
		if ($userId <= 0 || $entityId <= 0)
		{
			return null;
		}

		return $this->listService->buildRow(
			$userId,
			CollectionAccessService::buildUserAccessCodes($userId),
			$entityType,
			$entityId,
		);
	}
}
