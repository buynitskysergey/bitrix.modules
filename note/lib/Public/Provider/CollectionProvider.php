<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Provider;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Note\Internal\Model\Collection;
use Bitrix\Note\Internal\Repository\CollectionRepository;
use Bitrix\Note\Internal\Access\Service\CollectionAccessService;
use Bitrix\Note\Internal\Access\PortalAdmin;
use Bitrix\Note\Internal\Service\Document\MainDocumentService;

class CollectionProvider
{
	public const DEFAULT_LIMIT = 50;
	public const MAX_LIMIT = 200;

	private CollectionRepository $repository;

	private MainDocumentService $mainDocumentService;

	public function __construct(
		?CollectionRepository $repository = null,
		?MainDocumentService $mainDocumentService = null,
	)
	{
		$this->repository = $repository ?? new CollectionRepository();
		$this->mainDocumentService = $mainDocumentService ?? new MainDocumentService();
	}

	public function getById(int $id): ?Collection
	{
		return $this->repository->getById($id);
	}

	public function getList(int $limit = 0, int $offset = 0): array
	{
		$normalizedLimit = $limit > 0 ? $limit : null;
		$collections = $this->repository->getList($normalizedLimit, $offset);
		$result = [];

		foreach ($collections as $collection)
		{
			$result[] = $this->mapCollectionBase($collection);
		}

		return $result;
	}

	public function getAccessibleList(int $limit, ?array $afterCursor = null): array
	{
		$batch = $this->getAccessibleBatch($limit, $afterCursor);

		$items = [];
		foreach ($batch['entities'] as $collection)
		{
			$items[] = $this->mapCollectionBase($collection);
		}

		return [
			'items' => $items,
			'nextCursor' => $batch['nextCursor'],
		];
	}

	public function getAccessibleBatch(int $limit, ?array $afterCursor = null): array
	{
		$normalizedLimit = $this->normalizeLimit($limit);
		$fetchLimit = $normalizedLimit + 1;
		[$afterPosition, $afterId] = $this->normalizeCursor($afterCursor);

		$userId = (int)CurrentUser::get()->getId();
		$isAdmin = PortalAdmin::isCurrentUserAdmin();
		$effectiveMap = [];
		$policyMap = [];

		if ($isAdmin)
		{
			$collections = $this->repository->getList($fetchLimit, $afterPosition, $afterId);
		}
		else
		{
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
					'entities' => [],
					'nextCursor' => null,
					'effectiveMap' => $effectiveMap,
					'policyMap' => $policyMap,
					'isAdmin' => false,
				];
			}

			$collections = $this->repository->getListByIds($allowedIds, $fetchLimit, $afterPosition, $afterId);
		}

		$hasMore = count($collections) > $normalizedLimit;
		if ($hasMore)
		{
			$collections = array_slice($collections, 0, $normalizedLimit);
		}

		$nextCursor = null;
		if ($hasMore && !empty($collections))
		{
			$last = end($collections);
			$nextCursor = [
				'position' => (int)$last->getPosition(),
				'id' => (int)$last->getId(),
			];
		}

		return [
			'entities' => $collections,
			'nextCursor' => $nextCursor,
			'effectiveMap' => $effectiveMap,
			'policyMap' => $policyMap,
			'isAdmin' => $isAdmin,
		];
	}

	public function getManageableShort(int $limit): array
	{
		$normalizedLimit = $limit > 0 ? $limit : self::DEFAULT_LIMIT;
		$fetchLimit = $normalizedLimit + 1;

		$userId = (int)CurrentUser::get()->getId();
		$isAdmin = PortalAdmin::isCurrentUserAdmin();

		if ($isAdmin)
		{
			$collections = $this->repository->getList($fetchLimit);
		}
		else
		{
			$accessCodes = CollectionAccessService::buildUserAccessCodes($userId);
			$accessData = CollectionAccessService::getAllUserLevels($accessCodes);
			$manageableIds = [];
			foreach ($accessData['effective'] ?? [] as $cid => $level)
			{
				if ((int)$level >= CollectionAccessService::LEVEL_MANAGE)
				{
					$manageableIds[] = (int)$cid;
				}
			}

			if (empty($manageableIds))
			{
				return ['items' => [], 'hasMore' => false];
			}

			$collections = $this->repository->getListByIds($manageableIds, $fetchLimit);
		}

		$hasMore = count($collections) > $normalizedLimit;
		if ($hasMore)
		{
			$collections = array_slice($collections, 0, $normalizedLimit);
		}

		$items = [];
		foreach ($collections as $collection)
		{
			$items[] = [
				'id' => (int)$collection->getId(),
				'name' => (string)$collection->getName(),
			];
		}

		return ['items' => $items, 'hasMore' => $hasMore];
	}

	private function normalizeLimit(int $limit): int
	{
		if ($limit <= 0)
		{
			return self::DEFAULT_LIMIT;
		}

		return min($limit, self::MAX_LIMIT);
	}

	private function normalizeCursor(?array $cursor): array
	{
		if (!is_array($cursor))
		{
			return [null, null];
		}

		$position = isset($cursor['position']) ? (int)$cursor['position'] : null;
		$id = isset($cursor['id']) ? (int)$cursor['id'] : null;

		if ($position === null || $id === null || $id <= 0)
		{
			return [null, null];
		}

		return [$position, $id];
	}

	public function mapCollectionForCurrentUser(Collection $collection): array
	{
		$snapshot = CollectionAccessService::getCurrentUserAccessSnapshot((int)$collection->getId());

		return $this->mapCollectionWithLevels($collection, $snapshot['effective'], $snapshot['policy']);
	}

	public function mapCollectionWithLevels(Collection $collection, int $effectiveLevel, int $policyLevel): array
	{
		$row = $this->enrichWithAccess(
			$this->mapCollectionBase($collection),
			$effectiveLevel,
			$policyLevel,
		);

		return $this->enrichRowsWithMainDocument([$row])[0];
	}

	public function mapCollectionsWithAccess(
		array $collections,
		array $effectiveMap,
		array $policyMap,
		bool $isAdmin,
	): array
	{
		$rows = [];
		foreach ($collections as $collection)
		{
			$cid = $collection->getId();
			$effectiveLevel = $isAdmin
				? CollectionAccessService::LEVEL_MODERATE
				: ($effectiveMap[$cid] ?? CollectionAccessService::LEVEL_NONE)
			;
			$policyLevel = $policyMap[$cid] ?? (int)$collection->getPolicyLevel();

			$rows[] = $this->enrichWithAccess(
				$this->mapCollectionBase($collection),
				$effectiveLevel,
				$policyLevel,
			);
		}

		return $this->enrichRowsWithMainDocument($rows);
	}

	/**
	 * Adds the mainDocumentId and hasDescription fields to already-mapped collection rows
	 * using a single batch query - no per-collection round trips.
	 *
	 * @param array<int, array<string, mixed>> $rows each row must carry an 'id' key
	 * @return array<int, array<string, mixed>>
	 */
	private function enrichRowsWithMainDocument(array $rows): array
	{
		if (empty($rows))
		{
			return $rows;
		}

		$collectionIds = [];
		foreach ($rows as $row)
		{
			$collectionIds[] = (int)($row['id'] ?? 0);
		}

		$summary = $this->mainDocumentService->getSummaryByCollectionIds($collectionIds);

		foreach ($rows as &$row)
		{
			$meta = $summary[(int)($row['id'] ?? 0)] ?? null;
			$row['mainDocumentId'] = $meta['id'] ?? null;
			$row['hasDescription'] = $meta['hasDescription'] ?? false;
		}
		unset($row);

		return $rows;
	}

	private function enrichWithAccess(array $row, int $effectiveLevel, int $policyLevel): array
	{
		$row['policyLevel'] = CollectionAccessService::levelToCode($policyLevel);
		$row['canEditCollection'] = $effectiveLevel >= CollectionAccessService::LEVEL_MANAGE;
		$row['canManagePermissions'] = $effectiveLevel >= CollectionAccessService::LEVEL_MODERATE;

		return $row;
	}

	private function mapCollectionBase(Collection $collection): array
	{
		return [
			'id' => $collection->getId(),
			'name' => $collection->getName(),
			'createdBy' => $collection->getCreatedBy(),
			'position' => $collection->getPosition(),
			'createdAt' => $collection->getCreatedAt()->format('c'),
			'updatedBy' => $collection->getUpdatedBy(),
			'updatedAt' => $collection->getUpdatedAt()->format('c'),
			'policyLevel' => CollectionAccessService::levelToCode($collection->getPolicyLevel()),
		];
	}
}
