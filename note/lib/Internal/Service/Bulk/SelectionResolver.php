<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Bulk;

use Bitrix\Note\Internal\Configuration;
use Bitrix\Note\Internal\Repository\DocumentRepository;

/**
 * [FEAT-kb2-tree-bulk-archive / P1.T1] Expands a bulk selection of root document ids
 * into the full set of affected documents, deduplicated across overlapping subtrees.
 *
 * This is the single point where the selection-size cap is enforced — getSubtreeIds
 * itself is uncapped. The resolver is rights-agnostic: it only computes composition,
 * access is decided later by BulkAccessAggregator.
 */
class SelectionResolver
{
	public const SECTION_ACTIVE = 'active';
	public const SECTION_ARCHIVE = 'archive';
	public const SECTION_RECYCLE = 'recycle';

	public function __construct(
		private readonly DocumentRepository $documentRepository = new DocumentRepository(),
	) {}

	/**
	 * @param int[] $rootIds document ids the user selected as roots
	 * @param string $section one of SECTION_* — the section the selection lives in
	 * @param bool $withNested true expands each root into its whole subtree, false keeps roots only
	 *
	 * @return array{
	 *     ids: int[],
	 *     byCollection: array<int, int[]>,
	 *     limitExceeded: bool,
	 *     hasNested: bool,
	 *     rootsMeta: \Bitrix\Note\Internal\Model\Document[]
	 * } On limitExceeded the composition fields are empty — no partial expansion is exposed.
	 *   hasNested is reported regardless of withNested: the frontend needs it to decide whether
	 *   to offer the "with nested" toggle at all, before the toggle is set.
	 */
	public function resolve(array $rootIds, string $section, bool $withNested): array
	{
		// A collection's main document is never part of a bulk selection: it is hidden from every
		// listing, so it can only arrive here from a hand-crafted request.
		$roots = $this->documentRepository->getMetaByIds(
			$this->documentRepository->filterOutMainDocumentIds($rootIds),
		);
		$limit = Configuration::getBulkSelectionLimit();

		// Computed independently of $withNested: it answers "could the toggle matter here?".
		$hasNested = $this->anyRootHasChildren($roots);

		$seen = [];                 // documentId => true (dedup set across all roots)
		$byCollection = [];         // collectionId => int[] documentId

		foreach ($roots as $root)
		{
			$rootId = (int)$root->getId();
			$collectionId = (int)$root->getCollectionId();
			if ($rootId <= 0 || $collectionId <= 0)
			{
				continue;
			}

			// All subtree ids share the root's collection (getSubtreeIds filters by COLLECTION_ID),
			// so the whole expansion is grouped under a single collection key.
			$ids = $withNested
				? $this->documentRepository->getSubtreeIds(
					$rootId,
					$collectionId,
					$section === self::SECTION_RECYCLE,
					// Active-section bulk targets live documents: already-archived descendants
					// are not re-archived, so they must not inflate the dry-run affected count.
					excludeArchived: $section === self::SECTION_ACTIVE,
				)
				: [$rootId]
			;

			foreach ($ids as $id)
			{
				$id = (int)$id;
				if ($id <= 0 || isset($seen[$id]))
				{
					continue;
				}

				$seen[$id] = true;
				$byCollection[$collectionId][] = $id;
			}

			// Enforce the cap after each root so overlapping subtrees never quietly truncate.
			if (count($seen) > $limit)
			{
				return [
					'ids' => [],
					'byCollection' => [],
					'limitExceeded' => true,
					'hasNested' => $hasNested,
					'rootsMeta' => [],
				];
			}
		}

		return [
			'ids' => array_keys($seen),
			'byCollection' => $byCollection,
			'limitExceeded' => false,
			'hasNested' => $hasNested,
			'rootsMeta' => $roots,
		];
	}

	/**
	 * Whether at least one selected root has a direct live child. Cheap: one DISTINCT query per
	 * collection over PARENT_ID (getHasChildrenMap), never a recursive getSubtreeIds walk.
	 *
	 * @param \Bitrix\Note\Internal\Model\Document[] $roots
	 */
	private function anyRootHasChildren(array $roots): bool
	{
		$rootIdsByCollection = []; // collectionId => int[] rootId

		foreach ($roots as $root)
		{
			$rootId = (int)$root->getId();
			$collectionId = (int)$root->getCollectionId();
			if ($rootId <= 0 || $collectionId <= 0)
			{
				continue;
			}

			$rootIdsByCollection[$collectionId][] = $rootId;
		}

		foreach ($rootIdsByCollection as $collectionId => $ids)
		{
			$map = $this->documentRepository->getHasChildrenMap($collectionId, $ids);
			foreach ($map as $has)
			{
				if ($has)
				{
					return true;
				}
			}
		}

		return false;
	}
}
