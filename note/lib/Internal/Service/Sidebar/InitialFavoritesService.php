<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Sidebar;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Note\Internal\Service\Favorite\FavoriteListService;
use Bitrix\Note\Public\Provider\FavoriteProvider;

/**
 * [TPL-02] First page of the caller's favorites block, rendered into the page next to the initial
 * collections. Read-only and side-effect free: pull watches of the block are the same NOTE_COLLECTION_*
 * tags the collections service already registers.
 *
 * Same shape the AJAX list action answers with, so the client normalises both with one mapper.
 * `null` - not an empty page - when there is nothing to hand over: an empty page is a valid answer the
 * block hides itself on, so a failure must stay distinguishable from it and leave the block to ask the
 * server the way it did before.
 */
class InitialFavoritesService
{
	public function __construct(
		private readonly FavoriteProvider $provider = new FavoriteProvider(),
	) {}

	/**
	 * @return array{items: array<int, array<string, mixed>>, nextCursor: array{position: int, id: int}|null}|null
	 */
	public function resolve(int $pageSize = FavoriteListService::DEFAULT_LIMIT): ?array
	{
		$userId = (int)CurrentUser::get()->getId();
		if ($userId <= 0)
		{
			return null;
		}

		try
		{
			return $this->provider->getPage($userId, $pageSize);
		}
		catch (\Throwable)
		{
			return null;
		}
	}
}
