<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Provider;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Note\Internal\Model\FavoriteTable;
use Bitrix\Note\Internal\Repository\CollectionRepository;
use Bitrix\Note\Internal\Repository\FavoriteRepository;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Exceptions\AccessDeniedException;
use Bitrix\Note\Internal\Exceptions\CollectionNotFoundException;
use Bitrix\Note\Internal\Service\Document\DocumentCardMetaResolver;
use Bitrix\Note\Public\Service\AccessService;

class TreeProvider
{
	public const TREE_MAX_NODES = 5000;

	private DocumentRepository $repository;
	private CollectionRepository $collectionRepository;
	private DocumentCardMetaResolver $cardMetaResolver;

	public function __construct(
		?DocumentRepository $repository = null,
		?CollectionRepository $collectionRepository = null,
		?DocumentCardMetaResolver $cardMetaResolver = null,
	)
	{
		$this->repository = $repository ?? new DocumentRepository();
		$this->collectionRepository = $collectionRepository ?? new CollectionRepository();
		$this->cardMetaResolver = $cardMetaResolver ?? new DocumentCardMetaResolver();
	}

	public function getTree(int $collectionId): array
	{
		$documents = $this->repository->getCollectionDocuments($collectionId);
		$nodes = [];
		$tree = [];

		foreach ($documents as $document)
		{
			$nodes[$document->getId()] = [
				'id' => $document->getId(),
				'collectionId' => $document->getCollectionId(),
				'parentId' => $document->getParentId(),
				'title' => $document->getTitle(),
				'position' => $document->getPosition(),
				'isArchived' => $document->getIsArchived(),
				'children' => [],
			];
		}

		foreach ($nodes as $id => &$node)
		{
			$parentId = $node['parentId'];
			if ($parentId !== null && isset($nodes[$parentId]))
			{
				$nodes[$parentId]['children'][] = &$node;
			}
			else
			{
				$tree[] = &$node;
			}
		}
		unset($node);

		return $tree;
	}

	public function getAccessibleTree(int $collectionId): array
	{
		if ($this->collectionRepository->getById($collectionId) === null)
		{
			throw new CollectionNotFoundException();
		}

		if (!AccessService::canViewCollection($collectionId))
		{
			throw new AccessDeniedException();
		}

		$documents = $this->repository->getCollectionDocuments($collectionId);
		$nodes = [];
		foreach ($documents as $document)
		{
			$nodes[(int)$document->getId()] = [
				'id' => (int)$document->getId(),
				'collectionId' => (int)$document->getCollectionId(),
				'parentId' => $document->getParentId() !== null ? (int)$document->getParentId() : null,
				'title' => (string)$document->getTitle(),
				'position' => (int)$document->getPosition(),
				'children' => [],
			];
		}

		$rootIds = [];
		foreach ($nodes as $id => $node)
		{
			$parentId = $node['parentId'];
			if ($parentId === null || !isset($nodes[$parentId]))
			{
				$rootIds[] = $id;
			}
		}

		$truncated = false;
		$totalNodes = count($nodes);
		if ($totalNodes > self::TREE_MAX_NODES)
		{
			[$nodes, $rootIds, $truncated] = $this->truncateTreeByRoots($nodes, $rootIds);
		}

		foreach ($nodes as $id => &$node)
		{
			$parentId = $node['parentId'];
			if ($parentId !== null && isset($nodes[$parentId]))
			{
				$nodes[$parentId]['children'][] = &$node;
			}
		}
		unset($node);

		$items = [];
		foreach ($rootIds as $rootId)
		{
			if (isset($nodes[$rootId]))
			{
				$items[] = $nodes[$rootId];
			}
		}

		return [
			'items' => $items,
			'truncated' => $truncated,
		];
	}

	/**
	 * Returns paged children enriched with card meta (excerpt + author).
	 * Used by both the in-document children block (compact view) and any cards grid.
	 */
	public function getListByParent(
		int $collectionId,
		?int $parentId = null,
		int $limit = 50,
		?int $afterPosition = null,
		?int $afterId = null,
	): array
	{
		$normalizedLimit = $limit > 0 ? min($limit, 200) : 50;
		$rawDocuments = $this->repository->getDocumentMetaByParent(
			$collectionId,
			$parentId,
			$normalizedLimit + 1,
			$afterPosition,
			$afterId,
		);

		$hasNextPage = count($rawDocuments) > $normalizedLimit;
		$documents = $hasNextPage
			? array_slice($rawDocuments, 0, $normalizedLimit)
			: $rawDocuments
		;

		$documentIds = array_map(
			static fn(\Bitrix\Note\Internal\Model\Document $document): int => (int)$document->getId(),
			$documents,
		);
		$childrenCountMap = $this->repository->getChildrenCountMapByParentIds($collectionId, $documentIds);
		// [TPL-01] Star flag of the branch: one batch read per page. The branch is always read for the
		// current user, so the favorites owner comes from the ambient user, as elsewhere in the tree.
		$favoriteIds = array_flip((new FavoriteRepository())->findFavoriteEntityIds(
			(int)CurrentUser::get()->getId(),
			FavoriteTable::ENTITY_TYPE_DOCUMENT,
			$documentIds,
		));

		$authorIdByDocumentId = [];
		foreach ($documents as $document)
		{
			$authorIdByDocumentId[(int)$document->getId()] = (int)$document->getCreatedBy();
		}
		$cardMeta = $this->cardMetaResolver->resolve($authorIdByDocumentId);

		$result = [];
		$lastPosition = null;
		$lastId = null;
		foreach ($documents as $document)
		{
			$id = (int)$document->getId();
			$lastPosition = $document->getPosition();
			$lastId = $id;

			$meta = $cardMeta[$id] ?? ['excerpt' => '', 'author' => null];

			$result[] = [
				'id' => $id,
				'collectionId' => $document->getCollectionId(),
				'parentId' => $document->getParentId(),
				'title' => $document->getTitle(),
				'position' => $lastPosition,
				'hasChildren' => (int)($childrenCountMap[$id] ?? 0) > 0,
				'excerpt' => $meta['excerpt'],
				'author' => $meta['author'],
				'isFavorite' => isset($favoriteIds[$id]),
			];
		}

		return [
			'documents' => $result,
			'nextCursor' => $hasNextPage ? ['position' => $lastPosition, 'id' => $lastId] : null,
		];
	}

	private function truncateTreeByRoots(array $nodes, array $rootIds): array
	{
		$childrenByParent = [];
		foreach ($nodes as $id => $node)
		{
			$parentId = $node['parentId'];
			if ($parentId !== null && isset($nodes[$parentId]))
			{
				$childrenByParent[$parentId][] = $id;
			}
		}

		$keptNodes = [];
		$keptRoots = [];
		foreach ($rootIds as $rootId)
		{
			$remaining = self::TREE_MAX_NODES - count($keptNodes);
			if ($remaining <= 0)
			{
				break;
			}

			$subtree = $this->collectSubtreeBfs($rootId, $childrenByParent, $nodes, $remaining);

			if (count($subtree) > $remaining)
			{
				if (empty($keptRoots))
				{
					$subtree = array_slice($subtree, 0, $remaining, true);
				}
				else
				{
					break;
				}
			}

			$keptRoots[] = $rootId;
			foreach ($subtree as $id => $node)
			{
				$keptNodes[$id] = $node;
			}
		}

		return [$keptNodes, $keptRoots, true];
	}

	private function collectSubtreeBfs(int $rootId, array $childrenByParent, array $nodes, int $softLimit): array
	{
		$subtree = [];
		$queue = [$rootId];
		while (!empty($queue))
		{
			$current = array_shift($queue);
			$subtree[$current] = $nodes[$current];
			if (count($subtree) > $softLimit)
			{
				return $subtree;
			}
			if (isset($childrenByParent[$current]))
			{
				foreach ($childrenByParent[$current] as $childId)
				{
					$queue[] = $childId;
				}
			}
		}

		return $subtree;
	}
}
