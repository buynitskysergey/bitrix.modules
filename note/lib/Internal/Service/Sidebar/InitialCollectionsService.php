<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Sidebar;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Note\Internal\Access\AccessController;
use Bitrix\Note\Internal\Access\ActionDictionary;
use Bitrix\Note\Internal\Access\PortalAdmin;
use Bitrix\Note\Internal\Access\Service\CollectionAccessService;
use Bitrix\Note\Internal\Repository\CollectionRepository;
use Bitrix\Note\Internal\Service\Import\WikiImportAvailability;
use Bitrix\Note\Public\Provider\CollectionProvider;

class InitialCollectionsService
{
	private CollectionRepository $repository;

	private WikiImportAvailability $wikiImportAvailability;

	private const INITIAL_COLLECTIONS_PAGE_SIZE = 50;

	public function __construct(
		?CollectionRepository $repository = null,
		?WikiImportAvailability $wikiImportAvailability = null,
	)
	{
		$this->repository = $repository ?? new CollectionRepository();
		$this->wikiImportAvailability = $wikiImportAvailability ?? new WikiImportAvailability();
	}

	public function resolve(
		int $pageSize = self::INITIAL_COLLECTIONS_PAGE_SIZE,
		bool $registerPullWatches = true,
	): array
	{
		$normalizedPageSize = $pageSize > 0 ? $pageSize : 50;
		$globalPermissions = $this->resolveGlobalPermissions();
		$fetchLimit = $normalizedPageSize + 1;

		$userId = (int)CurrentUser::get()->getId();
		$isAdmin = PortalAdmin::isCurrentUserAdmin();
		$provider = new CollectionProvider($this->repository);

		try
		{
			if ($isAdmin)
			{
				$collections = $this->repository->getList($fetchLimit);

				return $this->buildResult(
					$provider->mapCollectionsWithAccess($collections, [], [], true),
					$collections,
					$normalizedPageSize,
					$globalPermissions,
					$registerPullWatches,
				);
			}

			$accessCodes = CollectionAccessService::buildUserAccessCodes($userId);
			$accessData = CollectionAccessService::getAllUserLevels($accessCodes);
			$effectiveMap = $accessData['effective'];
			$policyMap = $accessData['policy'];

			$allowedIds = [];
			foreach ($effectiveMap as $cid => $level)
			{
				if ($level >= CollectionAccessService::LEVEL_VIEW)
				{
					$allowedIds[] = $cid;
				}
			}

			if (empty($allowedIds))
			{
				return [
					'items' => [],
					'nextCursor' => null,
					'permissions' => $globalPermissions,
				];
			}

			$collections = $this->repository->getListByIds($allowedIds, $fetchLimit);

			return $this->buildResult(
				$provider->mapCollectionsWithAccess($collections, $effectiveMap, $policyMap, false),
				$collections,
				$normalizedPageSize,
				$globalPermissions,
				$registerPullWatches,
			);
		}
		catch (\Throwable)
		{
			return [
				'items' => [],
				'nextCursor' => null,
				'permissions' => $globalPermissions,
			];
		}
	}

	private function buildResult(
		array $rows,
		array $collections,
		int $pageSize,
		array $globalPermissions,
		bool $registerPullWatches = true,
	): array
	{
		$hasMore = count($collections) > $pageSize;
		if ($hasMore)
		{
			$rows = array_slice($rows, 0, $pageSize);
			$collections = array_slice($collections, 0, $pageSize);
		}

		$nextCursor = null;
		if ($hasMore && !empty($collections))
		{
			$last = end($collections);
			$nextCursor = [
				'position' => $last->getPosition(),
				'id' => $last->getId(),
			];
		}

		$items = array_values($rows);
		// Pull-watch registration is a side-effect write (~one row per collection).
		// Skip it when the caller only needs a read-only teaser payload (e.g. a
		// tariff-blocked entry that redirects away on slider close).
		if ($registerPullWatches)
		{
			$this->registerPullWatches($items);
		}

		return [
			'items' => $items,
			'nextCursor' => $nextCursor,
			'permissions' => $globalPermissions,
		];
	}

	private function registerPullWatches(array $items): void
	{
		if (!\Bitrix\Main\Loader::includeModule('pull'))
		{
			return;
		}

		$userId = (int)CurrentUser::get()->getId();
		if ($userId <= 0)
		{
			return;
		}

		\CPullWatch::Add($userId, 'NOTE_GLOBAL');
		foreach ($items as $item)
		{
			$collectionId = (int)($item['id'] ?? 0);
			if ($collectionId <= 0)
			{
				continue;
			}
			\CPullWatch::Add($userId, 'NOTE_COLLECTION_' . $collectionId);
			\CPullWatch::Add($userId, 'NOTE_COLLECTION_' . $collectionId . '_ACL');
		}
	}

	private function resolveGlobalPermissions(): array
	{
		$userId = (int)CurrentUser::get()->getId();
		$canImport = AccessController::getCurrent()->check(ActionDictionary::ACTION_NOTE_IMPORT);

		return [
			'canEditCollections' => AccessController::getCurrent()->check(ActionDictionary::ACTION_NOTE_CREATE_COLLECTIONS),
			'canEditGlobalPermissions' => AccessController::getCurrent()->check(ActionDictionary::ACTION_NOTE_EDIT_PERMISSIONS),
			'canImport' => $canImport,
			'canImportWiki' => $canImport && $this->wikiImportAvailability->isEnabled(),
			'hasManageableCollection' => CollectionAccessService::userHasManageableCollection($userId),
		];
	}
}
