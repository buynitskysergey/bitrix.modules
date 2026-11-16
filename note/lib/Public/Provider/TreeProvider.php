<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Provider;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Note\Internal\Access\Service\DocumentAccessService;
use Bitrix\Note\Internal\Model\FavoriteTable;
use Bitrix\Note\Internal\Repository\CollectionRepository;
use Bitrix\Note\Internal\Repository\FavoriteRepository;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Exceptions\AccessDeniedException;
use Bitrix\Note\Internal\Exceptions\CollectionNotFoundException;
use Bitrix\Note\Internal\Exceptions\CollectionTreeTooLargeException;
use Bitrix\Note\Internal\Service\Document\DocumentCardMetaResolver;
use Bitrix\Note\Internal\Service\Document\TreeNestingBuilder;
use Bitrix\Note\Internal\Service\Document\TreeSizeLimit;
use Bitrix\Note\Public\Service\AccessService;

class TreeProvider
{
	// Published for the clients of the provider; the value itself belongs to the domain, where the
	// public circuit reads it too.
	public const TREE_MAX_NODES = TreeSizeLimit::MAX_NODES;

	private DocumentRepository $repository;
	private CollectionRepository $collectionRepository;
	private DocumentCardMetaResolver $cardMetaResolver;
	private TreeNestingBuilder $nestingBuilder;

	public function __construct(
		?DocumentRepository $repository = null,
		?CollectionRepository $collectionRepository = null,
		?DocumentCardMetaResolver $cardMetaResolver = null,
		?TreeNestingBuilder $nestingBuilder = null,
	)
	{
		$this->repository = $repository ?? new DocumentRepository();
		$this->collectionRepository = $collectionRepository ?? new CollectionRepository();
		$this->cardMetaResolver = $cardMetaResolver ?? new DocumentCardMetaResolver();
		$this->nestingBuilder = $nestingBuilder ?? new TreeNestingBuilder();
	}

	public function getTree(int $collectionId): array
	{
		// Same rule as everywhere: a deleted base is read whole, a live one hides what lies in the
		// bin. Without it this method answers with an empty tree for a base whose documents all went
		// to the bin with it.
		$insideDeleted = !DocumentAccessService::isCollectionAlive($collectionId);
		$documents = $this->repository->getCollectionDocuments($collectionId, $insideDeleted);
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
		$insideDeleted = $this->assertCollectionReadable($collectionId);

		[$nodes, $rootIds] = $this->readTreeNodes($collectionId, $insideDeleted);

		$truncated = false;
		if (count($nodes) > self::TREE_MAX_NODES)
		{
			[$nodes, $rootIds, $truncated] = $this->nestingBuilder->truncateByRoots(
				$nodes,
				$rootIds,
				self::TREE_MAX_NODES,
			);
		}

		return [
			'items' => $this->nestingBuilder->nestNodes($nodes, $rootIds),
			'truncated' => $truncated,
		];
	}

	/**
	 * Says whether the tree is being read from inside a deleted base, which is read whole. The right
	 * that governs such a base is not the right to read it: a reader without it is answered as for a
	 * base that is not there, so the deletion does not leak through a 403.
	 */
	private function assertCollectionReadable(int $collectionId): bool
	{
		$collection = $this->collectionRepository->getById($collectionId);
		if ($collection === null)
		{
			throw new CollectionNotFoundException();
		}

		if ($collection->getTrashedAt() !== null)
		{
			$userId = (int)CurrentUser::get()->getId();
			if (!DocumentAccessService::canManageCollectionInRecycleBin($userId, $collectionId))
			{
				throw new CollectionNotFoundException();
			}

			return true;
		}

		if (!AccessService::canViewCollection($collectionId))
		{
			throw new AccessDeniedException();
		}

		return false;
	}

	/**
	 * @return array{0: array<int, array>, 1: int[]} flat nodes keyed by id, and the ids of the roots
	 */
	private function readTreeNodes(int $collectionId, bool $insideDeleted = false): array
	{
		$nodes = [];
		foreach ($this->repository->getCollectionDocuments($collectionId, $insideDeleted) as $document)
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
			// A node whose parent is out of reach is a root of what the caller can see, not a lost node.
			if ($node['parentId'] === null || !isset($nodes[$node['parentId']]))
			{
				$rootIds[] = $id;
			}
		}

		return [$nodes, $rootIds];
	}

	/**
	 * The tree of a collection whole, or a refusal - never a piece of it.
	 *
	 * The web path answers with a `truncated` flag and shows what it managed to read; a program cannot
	 * work with that. Half a collection looks exactly like the whole one, so a client that got it would
	 * draw a tree with branches silently missing and never learn which. The budget is therefore a wall
	 * here rather than a pair of scissors: over it the answer is a refusal.
	 *
	 * There is nothing to send the refused caller to yet: REST V3 has no listing of documents, so a
	 * collection over the budget cannot be walked branch by branch either. The web path walks it that
	 * way through note.document.listByParent, which is an Engine action and not part of REST.
	 *
	 * @return array[] roots with their children nested inside
	 * @throws CollectionTreeTooLargeException when the collection holds more nodes than the budget
	 */
	public function getWholeAccessibleTree(int $collectionId): array
	{
		$this->assertCollectionReadable($collectionId);
		// The REST path, and there the recycle bin does not exist: a deleted collection answers as a
		// missing one everywhere in REST, and an empty tree would say something different. The web path
		// above keeps its own answer, because the bin is a screen it has and REST has not.
		if (!DocumentAccessService::isCollectionAlive($collectionId))
		{
			throw new CollectionNotFoundException();
		}

		[$nodes, $rootIds] = $this->readTreeNodes($collectionId);
		if (count($nodes) > self::TREE_MAX_NODES)
		{
			throw new CollectionTreeTooLargeException(self::TREE_MAX_NODES);
		}

		return $this->nestingBuilder->nestNodes($nodes, $rootIds);
	}

	/**
	 * Returns paged children enriched with card meta (excerpt, author, date of the last change).
	 * Used by both the in-document children block and any cards grid.
	 */
	public function getListByParent(
		int $collectionId,
		?int $parentId = null,
		int $limit = 50,
		?int $afterPosition = null,
		?int $afterId = null,
	): array
	{
		// The branch of a deleted base is read whole, like its tree: the caller has already been
		// checked against the bin, here only the shape of the read follows from it.
		$insideDeleted = !DocumentAccessService::isCollectionAlive($collectionId);
		$normalizedLimit = $limit > 0 ? min($limit, 200) : 50;
		$rawDocuments = $this->repository->getDocumentMetaByParent(
			$collectionId,
			$parentId,
			$normalizedLimit + 1,
			$afterPosition,
			$afterId,
			$insideDeleted,
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
		$childrenCountMap = $this->repository->getChildrenCountMapByParentIds(
			$collectionId,
			$documentIds,
			$insideDeleted,
		);
		// Star flag of the branch: one batch read per page. The branch is always read for the
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
			$updatedAt = $document->getUpdatedAt();

			$result[] = [
				'id' => $id,
				'collectionId' => $document->getCollectionId(),
				'parentId' => $document->getParentId(),
				'title' => $document->getTitle(),
				'position' => $lastPosition,
				'hasChildren' => (int)($childrenCountMap[$id] ?? 0) > 0,
				// How many lie inside, for the card that draws this node: the sole of a card says the
				// number, and a bare "there are some" leaves it blank.
				'childCount' => (int)($childrenCountMap[$id] ?? 0),
				// Same shape the content list answers with (see NodeDto): a card of a live level and a
				// card of the archive print the date the same way, so they must be given it the same way.
				'changedAt' => $updatedAt !== null ? $updatedAt->format('c') : '',
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
}
