<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Command;

use Bitrix\Note\Internal\Model\Document;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Service\Bulk\BoundaryReparenter;

/**
 * Bulk move of exactly the selected documents. What travels is the selection and nothing else: a
 * selected node keeps the selected nodes below it and leaves everything else in the source base,
 * where the base command would have taken the whole subtree along.
 *
 * Three steps in a fixed order, per source knowledge base — an explicit selection may well come
 * from several. The selection is assembled under its own top nodes, then the children of the
 * selection that are not in it are re-hung onto the nearest node that stays, and only then does
 * the loop of the base command move the top nodes with PositionService. After the first two steps
 * a top node's subtree holds the selection alone, so the COLLECTION_ID cascade of the move has
 * nothing of the source base left to carry away.
 *
 * The three steps are three transactions, as everywhere else in the bulk pipeline. A failure
 * between them leaves the tree partly assembled and nothing to roll back; a repeat is safe,
 * because there is nothing left to assemble or to pull out.
 */
class MoveSelectedDocumentsCommand extends BulkMoveDocumentsCommand
{
	private ?BoundaryReparenter $boundaryReparenter = null;

	protected function prepareMove(array $allowedIds): ?array
	{
		if (empty($allowedIds))
		{
			return [];
		}

		// Before any write: a target inside the selection is not a per-node skip, it makes the whole
		// request unsatisfiable, and nothing of the tree may be touched on the way to saying so.
		if ($this->targetParentId !== null && in_array($this->targetParentId, $allowedIds, true))
		{
			return null;
		}

		$metaById = [];
		foreach ($this->repository->getMetaByIds($allowedIds, DocumentRepository::MOVE_META_SELECT) as $document)
		{
			$metaById[(int)$document->getId()] = $document;
		}

		$moveRootIds = [];
		$idsByCollection = [];
		foreach ($allowedIds as $id)
		{
			$id = (int)$id;
			$sourceCollectionId = (int)(($metaById[$id] ?? null)?->getCollectionId() ?? 0);
			if ($sourceCollectionId > 0)
			{
				$idsByCollection[$sourceCollectionId][] = $id;
			}
			else
			{
				// Raced out between the resolve and here; the move loop reports it as a transient skip.
				$moveRootIds[] = $id;
			}
		}

		foreach ($idsByCollection as $sourceCollectionId => $ids)
		{
			$moveRootIds = [...$moveRootIds, ...$this->prepareCollection($sourceCollectionId, $ids, $metaById)];
		}

		return $moveRootIds;
	}

	/**
	 * @param int[] $ids the selection of one source knowledge base
	 * @param array<int, Document> $metaById
	 * @return int[] the top nodes of that base that actually move
	 */
	private function prepareCollection(int $sourceCollectionId, array $ids, array $metaById): array
	{
		// Both maps are read before anything is written: the assembly rewrites the PARENT_IDs of the
		// selection, and both steps after it — the assembly itself, to tell which node is already in
		// place, and the re-hang, to group by — have to read the tree as it was.
		$parentByIdBeforeGather = $this->repository->getParentMap($sourceCollectionId, $ids);
		$ancestorsById = $this->repository->getAncestorIdsMap($ids);

		$movingTopIds = [];
		$stayingTopIds = [];
		foreach (BoundaryReparenter::topNodes($ids, $ancestorsById) as $topId)
		{
			if ($this->isAlreadyAtTarget($topId, $metaById))
			{
				$stayingTopIds[] = $topId;
			}
			else
			{
				$movingTopIds[] = $topId;
			}
		}

		if (empty($movingTopIds))
		{
			return [];
		}

		$syncTopIds = $this->narrowMovingTops($movingTopIds);
		if (empty($syncTopIds))
		{
			return [];
		}

		// A top node that does not travel — already at the target, or held back for a later run — keeps
		// its branch: it is neither taken apart nor emptied of its non-selected children. The rest of the
		// selection is assembled and cleared as usual, node by node rather than as a whole.
		$keptTopIds = [...$stayingTopIds, ...array_diff($movingTopIds, $syncTopIds)];
		$workingIds = $this->withoutBranchesOf($keptTopIds, $ids, $ancestorsById);

		$this->reparenter()->gatherSelected(
			$workingIds,
			$sourceCollectionId,
			$this->userId,
			$parentByIdBeforeGather,
		);
		$this->reparenter()->rehangOutsiders(
			$workingIds,
			$sourceCollectionId,
			$this->userId,
			$parentByIdBeforeGather,
		);

		return $syncTopIds;
	}

	/**
	 * Narrowing seam for the top nodes of one source base, asked BEFORE anything is written: the
	 * assembly and the clearing of the boundary that follow walk the set they are given and spend a
	 * transaction with an ACL sync per group, so an heir that leaves part of the set for later has to
	 * cut here and not on the way out. Identity by default.
	 *
	 * @param int[] $movingTopIds the top nodes of this base that are due to travel
	 * @return int[] the ones that travel now; the rest keep their branches untouched
	 */
	protected function narrowMovingTops(array $movingTopIds): array
	{
		return $movingTopIds;
	}

	/**
	 * @param array<int, Document> $metaById
	 */
	private function isAlreadyAtTarget(int $documentId, array $metaById): bool
	{
		$meta = $metaById[$documentId] ?? null;
		if ($meta === null)
		{
			return false;
		}

		$parentId = $meta->getParentId() === null ? null : (int)$meta->getParentId();

		return (int)$meta->getCollectionId() === $this->targetCollectionId && $parentId === $this->targetParentId;
	}

	/**
	 * @param int[] $stayingTopIds
	 * @param int[] $ids
	 * @param array<int, int[]> $ancestorsById
	 * @return int[]
	 */
	private function withoutBranchesOf(array $stayingTopIds, array $ids, array $ancestorsById): array
	{
		if (empty($stayingTopIds))
		{
			return $ids;
		}

		$staying = array_fill_keys($stayingTopIds, true);

		return array_values(array_filter($ids, static function (int $id) use ($staying, $ancestorsById): bool {
			if (isset($staying[$id]))
			{
				return false;
			}

			foreach ($ancestorsById[$id] ?? [] as $ancestorId)
			{
				if (isset($staying[(int)$ancestorId]))
				{
					return false;
				}
			}

			return true;
		}));
	}

	private function reparenter(): BoundaryReparenter
	{
		return $this->boundaryReparenter ??= new BoundaryReparenter($this->repository);
	}
}
