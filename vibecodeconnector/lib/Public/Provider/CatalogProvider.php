<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Public\Provider;

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\DateTime;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItem as InternalCatalogItem;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItemAccessType;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItemForUser;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItemType;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogListState;
use Bitrix\Vibecodeconnector\Internal\Integration\Main\AccessCodes;
use Bitrix\Vibecodeconnector\Internal\Integration\Main\IconStorageService;
use Bitrix\Vibecodeconnector\Internal\Integration\Main\UserNameResolver;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogItemFilter;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogItemPager;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogItemRepository;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogItemSort;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\ViewedRepository;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\NewApps\BaselineResolver;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\NewApps\NewAppsCountCache;
use Bitrix\Vibecodeconnector\Public\Dto\CatalogItem;
use Bitrix\Vibecodeconnector\Public\Dto\CatalogItemCollection;

final class CatalogProvider
{
	public function __construct(
		private readonly CatalogItemRepository $itemRepository = new CatalogItemRepository(),
		private readonly AccessCodes $accessCodes = new AccessCodes(),
		private readonly IconStorageService $iconStorage = new IconStorageService(),
		private readonly UserNameResolver $userNameResolver = new UserNameResolver(),
		private readonly BaselineResolver $baselineResolver = new BaselineResolver(),
		private readonly NewAppsCountCache $countCache = new NewAppsCountCache(),
		private readonly ViewedRepository $viewedRepository = new ViewedRepository(),
	) {}

	/**
	 * @param bool $markViewing Whether this listing is the user seeing their own catalog:
	 *                          only then the served items are marked as viewed.
	 * @param DateTime|null $viewSession Stamp of the New-state paging session, see CatalogItemFilter::viewSession().
	 * @param int|null $pageSize How many of the fetched items are actually served; the extra
	 *                           item callers fetch to detect the next page is never marked.
	 */
	public function listAccessibleToUser(
		int $userId,
		?string $query = null,
		int $offset = 0,
		int $limit = 20,
		CatalogListState $state = CatalogListState::All,
		bool $markViewing = false,
		?DateTime $viewSession = null,
		?int $pageSize = null,
	): CatalogItemCollection
	{
		$userCodes = $this->accessCodes->getUserCodes($userId);
		$baseline = $this->baselineResolver->resolveForUser($userId);

		if ($state === CatalogListState::New)
		{
			return $this->listNewApps(
				userId: $userId,
				userCodes: $userCodes,
				baseline: $baseline,
				query: $query,
				offset: $offset,
				limit: $limit,
				markViewing: $markViewing,
				viewSession: $viewSession,
				pageSize: $pageSize,
			);
		}

		$filter = (new CatalogItemFilter())
			->accessibleToUser($userId, $userCodes)
			->query($query)
			->hiddenState($state->hiddenPredicate())
		;

		$views = iterator_to_array($this->itemRepository->getListForUser(
			$userId,
			$filter,
			$this->pinAwareSort(),
			new CatalogItemPager($offset, $limit),
		));

		// Novelty is resolved for the page only, so the probe item the caller asks beyond the
		// page size always comes back with isNew = false. That is safe as long as the caller
		// drops it, which is what the pageSize it passes here means.
		$pageItemIds = $this->pageItemIds($views, $pageSize);
		$newAppItemIds = $this->itemRepository->getNewAppItemIds($userId, $userCodes, $baseline, $pageItemIds);

		$collection = $this->toCollection($views, $newAppItemIds);
		$this->markViewed($userId, $newAppItemIds, $markViewing);

		return $collection;
	}

	/**
	 * @param string[] $userCodes
	 */
	private function listNewApps(
		int $userId,
		array $userCodes,
		DateTime $baseline,
		?string $query,
		int $offset,
		int $limit,
		bool $markViewing,
		?DateTime $viewSession,
		?int $pageSize,
	): CatalogItemCollection {
		$filter = (new CatalogItemFilter())
			->accessibleToUser($userId, $userCodes)
			->type(CatalogItemType::Application)
			->ownerUserNotId($userId)
			->notViewedByUser(true)
			->viewSession($viewSession)
			->hiddenState(false)
			->query($query)
		;

		$views = iterator_to_array($this->itemRepository->getNewAppListForUser(
			$userId,
			$userCodes,
			$baseline,
			$filter,
			$this->pinAwareSort(),
			new CatalogItemPager($offset, $limit),
		));

		// The selection already carries both the novelty predicate and the session-aware
		// viewed one, so everything it serves is new for this session: a repeated request
		// of the first page keeps the badge on the cards it has just marked.
		$pageItemIds = $this->pageItemIds($views, $pageSize);

		$collection = $this->toCollection($views, $pageItemIds);
		$this->markViewed($userId, $pageItemIds, $markViewing);

		return $collection;
	}

	/**
	 * @param CatalogItemForUser[] $views
	 * @return int[]
	 */
	private function pageItemIds(array $views, ?int $pageSize): array
	{
		if ($pageSize !== null && count($views) > $pageSize)
		{
			$views = array_slice($views, 0, $pageSize);
		}

		return array_map(
			static fn(CatalogItemForUser $view): int => (int)$view->item->getId(),
			$views,
		);
	}

	/**
	 * The listing is the result the caller asked for, so a failed mark is logged and
	 * swallowed instead of breaking the response.
	 *
	 * @param int[] $newAppItemIds
	 */
	private function markViewed(int $userId, array $newAppItemIds, bool $markViewing): void
	{
		if (!$markViewing || $newAppItemIds === [])
		{
			return;
		}

		try
		{
			$this->viewedRepository->markViewed($userId, $newAppItemIds);
			$this->countCache->forget($userId);
		}
		catch (\Throwable $e)
		{
			\AddMessage2Log(
				'Failed to mark catalog items as viewed: ' . $e->getMessage(),
				'vibecodeconnector',
			);
		}
	}

	public function markCatalogOpenedForUser(int $userId): void
	{
		$this->baselineResolver->markCatalogOpened($userId);
	}

	public function countNewAppsForUser(int $userId, bool $useCache = true): int
	{
		$compute = fn(): int => $this->computeNewAppsCount($userId);

		return $useCache ? $this->countCache->remember($userId, $compute) : $compute();
	}

	public function forgetNewAppsCount(int $userId): void
	{
		$this->countCache->forget($userId);
	}

	private function computeNewAppsCount(int $userId): int
	{
		$userCodes = $this->accessCodes->getUserCodes($userId);
		$baseline = $this->baselineResolver->resolveForUser($userId);

		return $this->itemRepository->getNewAppCount($userId, $userCodes, $baseline);
	}

	public function listDiscoverableToUser(int $userId, ?string $query = null, int $offset = 0, int $limit = 20): CatalogItemCollection
	{
		$filter = (new CatalogItemFilter())
			->accessType(CatalogItemAccessType::ACL)
			->ownerUserNotId($userId)
			->notGrantedTo($this->accessCodes->getUserCodes($userId))
			->query($query)
		;

		$views = $this->itemRepository->getListForUser(
			$userId,
			$filter,
			(new CatalogItemSort())->byCreatedAt(),
			new CatalogItemPager($offset, $limit),
		);

		return $this->toCollection($views);
	}

	public function listNonPrivate(int $userId, ?string $query = null, int $offset = 0, int $limit = 20): CatalogItemCollection
	{
		$filter = (new CatalogItemFilter())
			->accessTypeNot(CatalogItemAccessType::Private)
			->query($query)
		;

		$views = $this->itemRepository->getListForUser(
			$userId,
			$filter,
			$this->pinAwareSort(),
			new CatalogItemPager($offset, $limit),
		);

		return $this->toCollection($views);
	}

	public function isEmptyForUser(int $userId): bool
	{
		return $this->listAccessibleToUser($userId, null, 0, 1)->isEmpty();
	}

	private function pinAwareSort(): CatalogItemSort
	{
		return (new CatalogItemSort())
			->byPinned()
			->byPinnedAt()
			->byOwnedByUser()
			->byLastOpened()
			->byLastOpenedAt()
			->byCreatedAt()
		;
	}

	/**
	 * @param iterable<CatalogItemForUser> $views
	 * @param int[] $newAppItemIds
	 */
	private function toCollection(iterable $views, array $newAppItemIds = []): CatalogItemCollection
	{
		$views = is_array($views) ? $views : iterator_to_array($views);

		$ownerIds = array_map(
			static fn(CatalogItemForUser $view): int => $view->item->getOwnerId(),
			$views,
		);
		$ownerNames = $this->userNameResolver->resolveMany($ownerIds);

		$iconUrls = $this->resolveIconUrls($views);
		$newAppItemIdSet = array_flip($newAppItemIds);

		$dtos = [];
		foreach ($views as $view)
		{
			$ownerId = $view->item->getOwnerId();
			$iconFileId = $view->item->getIconFileId();
			$iconUrl = $iconFileId !== null ? ($iconUrls[$iconFileId] ?? null) : null;
			$isNew = isset($newAppItemIdSet[$view->item->getId()]);
			$dtos[] = $this->toDto($view, $ownerNames[$ownerId] ?? null, $iconUrl, $isNew);
		}

		return new CatalogItemCollection(...$dtos);
	}

	/**
	 * Batch-resolves icon URLs for a list so serialization does not issue one
	 * b_file query per element (N+1).
	 *
	 * @param array<CatalogItemForUser> $views
	 * @return array<int, string> map of iconFileId => public URL
	 */
	private function resolveIconUrls(array $views): array
	{
		$iconFileIds = [];
		foreach ($views as $view)
		{
			$iconFileId = $view->item->getIconFileId();
			if ($iconFileId !== null)
			{
				$iconFileIds[] = $iconFileId;
			}
		}

		if ($iconFileIds === [])
		{
			return [];
		}

		return $this->iconStorage->getPublicUrls($iconFileIds);
	}

	private function resolveDescription(InternalCatalogItem $item): string
	{
		return $this->resolveDisplayDescription($item->getType(), $item->getDescription());
	}

	public function resolveDisplayDescription(CatalogItemType $type, ?string $storedDescription): string
	{
		if ($storedDescription !== null && $storedDescription !== '')
		{
			return $storedDescription;
		}

		$messageId = match ($type)
		{
			CatalogItemType::Bot => 'VIBECODECONNECTOR_CATALOG_PROVIDER_DESCRIPTION_DEFAULT_BOT',
			CatalogItemType::Application => 'VIBECODECONNECTOR_CATALOG_PROVIDER_DESCRIPTION_DEFAULT_APPLICATION',
		};

		return (string)Loc::getMessage($messageId);
	}

	private function toDto(
		CatalogItemForUser $view,
		?string $ownerName = null,
		?string $iconUrl = null,
		bool $isNew = false,
	): CatalogItem
	{
		$item = $view->item;
		$rawDescription = $item->getDescription();
		$isDescriptionDefault = ($rawDescription === null || $rawDescription === '');

		return new CatalogItem(
			id: (int)$item->getId(),
			kind: $item->getType()->value,
			title: $item->getTitle(),
			description: $this->resolveDescription($item),
			iconUrl: $iconUrl,
			editUrl: $item->getEditUrl(),
			viewUrl: $item->getViewUrl(),
			chatId: $item->getChatId(),
			externalId: $item->getExternalId(),
			ownerId: $item->getOwnerId(),
			ownerName: $ownerName,
			color: $item->getColor(),
			isPinned: $view->isPinned,
			isMine: $view->isOwnedByUser,
			createdAt: $item->getCreatedAt()?->getTimestamp(),
			isHidden: $view->isHidden,
			isNew: $isNew,
			isDescriptionDefault: $isDescriptionDefault,
		);
	}
}
