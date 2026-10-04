<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Sidebar;

use Bitrix\Main\ORM\Query\Query;
use Bitrix\Note\Internal\Access\PortalAdmin;
use Bitrix\Note\Internal\Access\Service\CollectionAccessService;
use Bitrix\Note\Internal\Access\Service\DocumentAccessService;
use Bitrix\Note\Internal\Model\CollectionTable;
use Bitrix\Note\Internal\Model\DocumentAccessTable;
use Bitrix\Note\Internal\Model\DocumentTable;
use Bitrix\Note\Internal\Model\FavoriteTable;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Repository\FavoriteRepository;
use Bitrix\Note\Internal\Service\RecycleBin\RecycleBinFilter;

/**
 * [API-03] Server-side read of the pruned tree of documents a user can reach WITHOUT any
 * collection-VIEW grant (document-level access only). Nodes carry just enough to rebuild the
 * hierarchy on the client (id, parentId, title, hasChildren), grouped by their container
 * collection. Access is decided through the shared source-aware DocumentAccessService formula
 * (batchGetEffectiveLevels) — never a separate raw-SQL reimplementation — so listing visibility
 * stays identical to the point/search/shared/archive locuses.
 *
 * Privacy: inaccessible intermediate ancestor documents are never surfaced; the nearest
 * accessible descendant becomes a branch root (parentId=null). Only the container collection is
 * exposed as a grouping.
 */
final class AccessibleTreeService
{
	private const MAX_LIMIT = 200;
	private const HAS_CHILDREN_PROBE_BATCH = 200;
	// Backstop for the hasChildren walk: normally every parent is answered by its first candidate,
	// so one page is enough. The bound keeps a pathological branch (or a broken cursor) from turning
	// a listing request into an unbounded scan — hasChildren is a hint, so degrading to false is
	// preferable to hanging.
	private const HAS_CHILDREN_MAX_PAGES = 50;
	// A candidate page can be emptied entirely by the access filter or by the branch-root rule (a
	// materialised subtree longer than the window yields nothing but non-roots). Returning that empty
	// page would make the section look empty while the cursor says otherwise, so the walk continues
	// server-side until something is showable. Bounded: past the cap the empty page plus its cursor
	// goes back to the client, which keeps walking.
	private const ROOT_SCAN_MAX_PAGES = 20;

	public function __construct(
		private readonly DocumentRepository $documentRepository = new DocumentRepository(),
		// Page size of the hasChildren probe; injectable so the multi-page path is testable without
		// creating a batch worth of documents.
		private readonly int $probePageSize = self::HAS_CHILDREN_PROBE_BATCH,
	) {}

	/**
	 * Returns one page of branch roots. A candidate window can be emptied by the access filter or by
	 * the branch-root rule, so the cursor is walked internally until the page has roots to show or
	 * the set runs out — an empty page with a live cursor is only returned at the scan cap.
	 *
	 * @param array<int, string> $accessCodes
	 * @param array{collectionId: int, id: int}|null $afterCursor
	 * @return array{
	 *   containers: array<int, array{collectionId: int, title: string, nodes: array<int, array{id: int, parentId: int|null, title: string, position: int, hasChildren: bool}>}>,
	 *   nextCursor: array{collectionId: int, id: int}|null
	 * }
	 */
	public function list(int $userId, array $accessCodes, int $limit, ?array $afterCursor = null): array
	{
		$empty = ['containers' => [], 'nextCursor' => null];

		if ($userId <= 0)
		{
			return $empty;
		}

		// A portal admin sees every collection in full, so nothing is reachable "without
		// collection access" — mirrors listSharedWithMeIds.
		if (PortalAdmin::isCurrentUserAdmin())
		{
			return $empty;
		}

		$limit = max(1, min(self::MAX_LIMIT, $limit));

		$codesPersonal = array_values(array_unique(array_filter(
			$accessCodes,
			static fn($code) => is_string($code) && $code !== '' && $code !== '*',
		)));
		if (empty($codesPersonal))
		{
			return $empty;
		}

		$accessibleCollectionIds = $this->resolveAccessibleCollectionIds($accessCodes);

		$cursor = $afterCursor;
		$nextCursor = null;
		$rootNodes = [];
		$parentInSet = [];

		for ($page = 0; $page < self::ROOT_SCAN_MAX_PAGES; $page++)
		{
			$candidates = $this->fetchCandidatePage($codesPersonal, $accessibleCollectionIds, $limit, $cursor);

			// nextCursor advances by the last CANDIDATE (not the last kept row), so rows dropped by
			// the post-fetch access filter are never skipped; a full window means there may be more.
			$pageCursor = null;
			if (count($candidates) >= $limit)
			{
				$last = end($candidates);
				$pageCursor = ['collectionId' => (int)$last['collectionId'], 'id' => (int)$last['id']];
			}
			$nextCursor = $pageCursor;

			if (empty($candidates))
			{
				break;
			}

			$keptIds = $this->filterAccessible($candidates, $accessCodes, $userId);
			if (!empty($keptIds))
			{
				$keptSet = array_flip($keptIds);
				$keptNodes = array_values(array_filter(
					$candidates,
					static fn(array $row): bool => isset($keptSet[(int)$row['id']]),
				));

				$pageParents = $this->resolveParentsInSet($keptNodes, $accessibleCollectionIds, $accessCodes, $userId);

				// The section page carries BRANCH ROOTS only; deeper levels are fetched per parent by
				// listChildren, exactly as the collection tree fetches them via listByParent. Root-ness
				// is a global property (parents are resolved against the whole table, not the current
				// page), so a node dropped here can never turn out to be a root on a later page.
				$pageRoots = array_values(array_filter(
					$keptNodes,
					static fn(array $row): bool => (int)$row['parentId'] <= 0 || !isset($pageParents[(int)$row['parentId']]),
				));

				if (!empty($pageRoots))
				{
					$rootNodes = $pageRoots;
					$parentInSet = $pageParents;

					break;
				}
			}

			if ($pageCursor === null)
			{
				break;
			}

			$cursor = $pageCursor;
		}

		if (empty($rootNodes))
		{
			return ['containers' => [], 'nextCursor' => $nextCursor];
		}

		$rootIds = array_map(static fn(array $row): int => (int)$row['id'], $rootNodes);
		$hasChildrenMap = $this->resolveHasChildren(
			$rootIds,
			$codesPersonal,
			$accessibleCollectionIds,
			$accessCodes,
			$userId,
		);

		return [
			'containers' => $this->groupIntoContainers(
				$rootNodes,
				$parentInSet,
				$hasChildrenMap,
				$this->resolveFavoriteIds($userId, $rootIds),
			),
			'nextCursor' => $nextCursor,
		];
	}

	/**
	 * [API-03b] ACL-aware direct children of one accessible document. The regular listByParent
	 * endpoint cannot serve this section: it requires collection VIEW, which a document-grant
	 * recipient by definition lacks. Ordering and cursor shape match the collection tree
	 * (POSITION DESC, ID DESC / {position, id}) so both trees paginate identically, and access is
	 * decided by the same batchGetEffectiveLevels formula as everywhere else.
	 *
	 * @param array<int, string> $accessCodes
	 * @param array{position: int, id: int}|null $afterCursor
	 * @return array{
	 *   documents: array<int, array{id: int, collectionId: int, parentId: int, title: string, position: int, hasChildren: bool}>,
	 *   nextCursor: array{position: int, id: int}|null
	 * }
	 */
	public function listChildren(
		int $userId,
		array $accessCodes,
		int $collectionId,
		int $parentId,
		int $limit,
		?array $afterCursor = null,
	): array
	{
		$empty = ['documents' => [], 'nextCursor' => null];

		if ($userId <= 0 || $collectionId <= 0 || $parentId <= 0)
		{
			return $empty;
		}

		if (PortalAdmin::isCurrentUserAdmin())
		{
			return $empty;
		}

		$limit = max(1, min(self::MAX_LIMIT, $limit));

		$codesPersonal = array_values(array_unique(array_filter(
			$accessCodes,
			static fn($code) => is_string($code) && $code !== '' && $code !== '*',
		)));
		if (empty($codesPersonal))
		{
			return $empty;
		}

		$accessibleCollectionIds = $this->resolveAccessibleCollectionIds($accessCodes);
		if (in_array($collectionId, $accessibleCollectionIds, true))
		{
			// The collection is viewable, so this branch belongs to the regular tree, not here.
			return $empty;
		}

		if (!$this->isNodeAccessible($parentId, $collectionId, $accessCodes, $userId))
		{
			return $empty;
		}

		$afterPosition = isset($afterCursor['position']) ? (int)$afterCursor['position'] : null;
		$afterId = isset($afterCursor['id']) ? (int)$afterCursor['id'] : null;
		if ($afterId === null || $afterId <= 0)
		{
			$afterPosition = null;
			$afterId = null;
		}

		$nextCursor = null;
		$kept = [];

		// A window can be emptied by the access filter, and an empty page would make the branch look
		// childless while its cursor says otherwise. Same walk as list(), same bound.
		for ($page = 0; $page < self::ROOT_SCAN_MAX_PAGES; $page++)
		{
			// +1 lookahead tells "there is another page" without a second COUNT query.
			$rows = $this->documentRepository->getDocumentMetaByParent(
				$collectionId,
				$parentId,
				$limit + 1,
				$afterPosition,
				$afterId,
			);
			if (empty($rows))
			{
				$nextCursor = null;

				break;
			}

			$candidates = array_slice($rows, 0, $limit);
			$nextCursor = null;
			if (count($rows) > $limit)
			{
				// The cursor advances by the last CANDIDATE (not the last kept row), so rows dropped
				// by the access filter below are never skipped on the next page.
				$last = end($candidates);
				$nextCursor = ['position' => (int)$last->getPosition(), 'id' => (int)$last->getId()];
			}

			$docs = array_map(
				static fn($document): array => [
					'id' => (int)$document->getId(),
					'collectionId' => $collectionId,
				],
				$candidates,
			);
			$levels = DocumentAccessService::batchGetEffectiveLevels($docs, $accessCodes, $userId);

			foreach ($candidates as $document)
			{
				$id = (int)$document->getId();
				if (($levels[$id] ?? DocumentAccessService::LEVEL_NONE) < DocumentAccessService::LEVEL_VIEW)
				{
					continue;
				}

				$kept[$id] = $document;
			}

			if (!empty($kept) || $nextCursor === null)
			{
				break;
			}

			$afterPosition = $nextCursor['position'];
			$afterId = $nextCursor['id'];
		}

		if (empty($kept))
		{
			return ['documents' => [], 'nextCursor' => $nextCursor];
		}

		$hasChildrenMap = $this->resolveHasChildren(
			array_keys($kept),
			$codesPersonal,
			$accessibleCollectionIds,
			$accessCodes,
			$userId,
		);

		$favoriteIds = $this->resolveFavoriteIds($userId, array_keys($kept));

		$documents = [];
		foreach ($kept as $id => $document)
		{
			$documents[] = [
				'id' => $id,
				'collectionId' => $collectionId,
				'parentId' => $parentId,
				'title' => (string)$document->getTitle(),
				'position' => (int)$document->getPosition(),
				'hasChildren' => isset($hasChildrenMap[$id]),
				'isFavorite' => isset($favoriteIds[$id]),
			];
		}

		return ['documents' => $documents, 'nextCursor' => $nextCursor];
	}

	/**
	 * [ALG-02] Which of these documents own at least one child this user may see. Same question the
	 * listing above answers for its own nodes, asked from outside: a surface that expands a document
	 * through this namespace (the favorites block) must decide its chevron by what the branch will
	 * actually hold, not by whether the document has children at all.
	 *
	 * Only meaningful for documents whose collection is closed to the user — inside a viewable
	 * collection the branch is read through the regular tree, where every live child shows anyway.
	 *
	 * @param int[] $documentIds
	 * @param array<int, string> $accessCodes access codes of $userId (same-user invariant)
	 * @return array<int, true> set of ids owning an accessible child
	 */
	public function hasAccessibleChildren(array $documentIds, array $accessCodes, int $userId): array
	{
		if ($userId <= 0 || empty($documentIds))
		{
			return [];
		}

		$codesPersonal = array_values(array_unique(array_filter(
			$accessCodes,
			static fn($code) => is_string($code) && $code !== '' && $code !== '*',
		)));
		if (empty($codesPersonal))
		{
			return [];
		}

		return $this->resolveHasChildren(
			$documentIds,
			$codesPersonal,
			$this->resolveAccessibleCollectionIds($accessCodes),
			$accessCodes,
			$userId,
		);
	}

	/**
	 * Is this document reachable in the accessible-tree sense: alive, in the given collection and
	 * granted VIEW+ through the shared document-access formula.
	 *
	 * @param array<int, string> $accessCodes
	 */
	private function isNodeAccessible(int $documentId, int $collectionId, array $accessCodes, int $userId): bool
	{
		$rows = $this->documentRepository->getByIds([$documentId], ['ID', 'COLLECTION_ID', 'IS_ARCHIVED']);
		$row = $rows[$documentId] ?? null;
		if ($row === null || ($row['IS_ARCHIVED'] ?? 'N') === 'Y' || (int)$row['COLLECTION_ID'] !== $collectionId)
		{
			return false;
		}

		$levels = DocumentAccessService::batchGetEffectiveLevels(
			[['id' => $documentId, 'collectionId' => $collectionId]],
			$accessCodes,
			$userId,
		);

		return ($levels[$documentId] ?? DocumentAccessService::LEVEL_NONE) >= DocumentAccessService::LEVEL_VIEW;
	}

	/**
	 * @param array<int, string> $accessCodes
	 * @return int[]
	 */
	private function resolveAccessibleCollectionIds(array $accessCodes): array
	{
		$collectionLevels = CollectionAccessService::getAllUserLevels($accessCodes)['effective'] ?? [];
		$ids = [];
		foreach ($collectionLevels as $cid => $level)
		{
			if ((int)$level >= CollectionAccessService::LEVEL_VIEW)
			{
				$ids[] = (int)$cid;
			}
		}

		return $ids;
	}

	/**
	 * Candidate page: documents the user holds SOME ACL row on, living in a collection they
	 * cannot VIEW, non-archived, not in the recycle bin. Keyset over (COLLECTION_ID ASC, ID DESC).
	 * This narrows the set only — the effective grant level is decided by batchGetEffectiveLevels
	 * afterwards, so the bare "has a row" membership test here is intentional, not the ALG-01/02
	 * formula.
	 *
	 * The grant test is an uncorrelated IN-subquery over IX_NOTE_DOC_ACCESS_SUBJECT
	 * (SUBJECT_CODE, DOCUMENT_ID, LEVEL): the engine materialises it once, and unlike a JOIN it
	 * cannot multiply a document that carries several matching grants.
	 *
	 * @param string[] $codesPersonal
	 * @param int[] $accessibleCollectionIds
	 * @param array{collectionId: int, id: int}|null $afterCursor
	 * @return array<int, array{id: int, collectionId: int, parentId: int, title: string, position: int}>
	 */
	private function fetchCandidatePage(
		array $codesPersonal,
		array $accessibleCollectionIds,
		int $limit,
		?array $afterCursor,
	): array
	{
		$grantedDocuments = DocumentAccessTable::query()
			->setSelect(['DOCUMENT_ID'])
			->whereIn('SUBJECT_CODE', $codesPersonal)
		;

		$query = DocumentTable::query()
			->setSelect(['ID', 'COLLECTION_ID', 'PARENT_ID', 'TITLE', 'POSITION'])
			->where('IS_ARCHIVED', 'N')
			->whereIn('ID', $grantedDocuments)
			->addOrder('COLLECTION_ID', 'ASC')
			->addOrder('ID', 'DESC')
			->setLimit($limit)
		;
		(new RecycleBinFilter())->applyExclusion($query);

		if (!empty($accessibleCollectionIds))
		{
			$query->whereNotIn('COLLECTION_ID', $accessibleCollectionIds);
		}

		$afterCollectionId = isset($afterCursor['collectionId']) ? (int)$afterCursor['collectionId'] : 0;
		$afterId = isset($afterCursor['id']) ? (int)$afterCursor['id'] : 0;
		if ($afterCollectionId > 0 && $afterId > 0)
		{
			$query->where(
				Query::filter()->logic('or')
					->where('COLLECTION_ID', '>', $afterCollectionId)
					->where(
						Query::filter()
							->where('COLLECTION_ID', $afterCollectionId)
							->where('ID', '<', $afterId)
					)
			);
		}

		$rows = [];
		$result = $query->exec();
		while ($row = $result->fetch())
		{
			$rows[] = [
				'id' => (int)$row['ID'],
				'collectionId' => (int)$row['COLLECTION_ID'],
				'parentId' => isset($row['PARENT_ID']) ? (int)$row['PARENT_ID'] : 0,
				'title' => (string)($row['TITLE'] ?? ''),
				'position' => (int)($row['POSITION'] ?? 0),
			];
		}

		return $rows;
	}

	/**
	 * @param array<int, array{id: int, collectionId: int}> $candidates
	 * @param array<int, string> $accessCodes
	 * @return int[]
	 */
	private function filterAccessible(array $candidates, array $accessCodes, int $userId): array
	{
		$docs = array_map(
			static fn(array $row): array => ['id' => (int)$row['id'], 'collectionId' => (int)$row['collectionId']],
			$candidates,
		);
		$levels = DocumentAccessService::batchGetEffectiveLevels($docs, $accessCodes, $userId);

		$kept = [];
		foreach ($candidates as $row)
		{
			$id = (int)$row['id'];
			if (($levels[$id] ?? DocumentAccessService::LEVEL_NONE) >= DocumentAccessService::LEVEL_VIEW)
			{
				$kept[] = $id;
			}
		}

		return $kept;
	}

	/**
	 * Which of the kept nodes' parents are themselves in the accessible-without-collection set.
	 * A parent qualifies only under the same rule as its children (non-archived, collection not
	 * viewable, doc-grant VIEW+); otherwise the child's branch is truncated (parentId=null) so an
	 * inaccessible ancestor is never revealed.
	 *
	 * @param array<int, array{parentId: int}> $keptNodes
	 * @param int[] $accessibleCollectionIds
	 * @param array<int, string> $accessCodes
	 * @return array<int, true> set of parent ids that are in the accessible set
	 */
	private function resolveParentsInSet(
		array $keptNodes,
		array $accessibleCollectionIds,
		array $accessCodes,
		int $userId,
	): array
	{
		$parentIds = [];
		foreach ($keptNodes as $node)
		{
			$pid = (int)$node['parentId'];
			if ($pid > 0)
			{
				$parentIds[$pid] = true;
			}
		}
		if (empty($parentIds))
		{
			return [];
		}

		$rows = $this->documentRepository->getByIds(
			array_keys($parentIds),
			['ID', 'COLLECTION_ID', 'IS_ARCHIVED'],
		);

		$accessibleSet = array_flip($accessibleCollectionIds);
		$docs = [];
		foreach ($rows as $id => $row)
		{
			if (($row['IS_ARCHIVED'] ?? 'N') === 'Y')
			{
				continue;
			}
			$cid = (int)$row['COLLECTION_ID'];
			if (isset($accessibleSet[$cid]))
			{
				continue;
			}
			$docs[] = ['id' => (int)$id, 'collectionId' => $cid];
		}
		if (empty($docs))
		{
			return [];
		}

		$levels = DocumentAccessService::batchGetEffectiveLevels($docs, $accessCodes, $userId);
		$inSet = [];
		foreach ($docs as $doc)
		{
			if (($levels[$doc['id']] ?? DocumentAccessService::LEVEL_NONE) >= DocumentAccessService::LEVEL_VIEW)
			{
				$inSet[$doc['id']] = true;
			}
		}

		return $inSet;
	}

	/**
	 * Marks kept nodes that own at least one accessible direct child (something to expand). The
	 * child may only surface on a later page, so this is a hint, not the child payload itself.
	 *
	 * @param int[] $keptIds
	 * @param string[] $codesPersonal
	 * @param int[] $accessibleCollectionIds
	 * @param array<int, string> $accessCodes
	 * @return array<int, true> set of parent ids that have an accessible child
	 */
	private function resolveHasChildren(
		array $keptIds,
		array $codesPersonal,
		array $accessibleCollectionIds,
		array $accessCodes,
		int $userId,
	): array
	{
		$remaining = array_flip(array_map(static fn($id): int => (int)$id, $keptIds));
		if (empty($remaining) || empty($codesPersonal))
		{
			return [];
		}

		// One visible child answers a parent for good, so the walk proceeds in bounded pages over
		// (PARENT_ID ASC, ID DESC) and re-issues the query against the parents still unanswered.
		// A wide granted branch therefore costs one page, not one row per child: as soon as its
		// first visible child is found, the parent leaves the filter and the rest is never read.
		$hasChildren = [];
		$afterParentId = 0;
		$afterId = 0;
		$pages = 0;
		while (!empty($remaining) && $pages < self::HAS_CHILDREN_MAX_PAGES)
		{
			$pages++;
			$page = $this->fetchChildProbePage(
				array_keys($remaining),
				$codesPersonal,
				$accessibleCollectionIds,
				$afterParentId,
				$afterId,
			);
			if (empty($page))
			{
				break;
			}

			$last = end($page);
			$afterParentId = $last['parentId'];
			$afterId = $last['id'];

			$batch = [];
			$parentByChild = [];
			foreach ($page as $row)
			{
				$batch[] = ['id' => $row['id'], 'collectionId' => $row['collectionId']];
				$parentByChild[$row['id']] = $row['parentId'];
			}

			foreach ($this->probeVisibleParents($batch, $parentByChild, $accessCodes, $userId) as $answered => $unused)
			{
				$hasChildren[$answered] = true;
				unset($remaining[$answered]);
			}
		}

		return $hasChildren;
	}

	/**
	 * One keyset page of candidate children for the hasChildren probe. Only documents carrying an
	 * ACL row for this user's codes can ever be visible here (their collection is closed to them),
	 * so the scan is narrowed by the same granted-ids subquery the candidate page uses. Archive and
	 * recycle bin are excluded exactly as in the collection tree — a parent whose only children are
	 * trashed must not offer a disclosure chevron.
	 *
	 * @param int[] $parentIds
	 * @param string[] $codesPersonal
	 * @param int[] $accessibleCollectionIds
	 * @return array<int, array{id: int, parentId: int, collectionId: int}>
	 */
	private function fetchChildProbePage(
		array $parentIds,
		array $codesPersonal,
		array $accessibleCollectionIds,
		int $afterParentId,
		int $afterId,
	): array
	{
		$grantedDocuments = DocumentAccessTable::query()
			->setSelect(['DOCUMENT_ID'])
			->whereIn('SUBJECT_CODE', $codesPersonal)
		;

		$query = DocumentTable::query()
			->setSelect(['ID', 'COLLECTION_ID', 'PARENT_ID'])
			->whereIn('PARENT_ID', $parentIds)
			->whereIn('ID', $grantedDocuments)
			->where('IS_ARCHIVED', 'N')
			->addOrder('PARENT_ID', 'ASC')
			->addOrder('ID', 'DESC')
			->setLimit(max(1, $this->probePageSize))
		;
		(new RecycleBinFilter())->applyExclusion($query);

		if (!empty($accessibleCollectionIds))
		{
			$query->whereNotIn('COLLECTION_ID', $accessibleCollectionIds);
		}

		// Keyset over the same (PARENT_ID, ID) order, so a parent whose children span several pages
		// is resumed instead of restarted.
		if ($afterParentId > 0 && $afterId > 0)
		{
			$query->where(
				Query::filter()->logic('or')
					->where('PARENT_ID', '>', $afterParentId)
					->where(
						Query::filter()
							->where('PARENT_ID', $afterParentId)
							->where('ID', '<', $afterId)
					)
			);
		}

		$rows = [];
		$result = $query->exec();
		while ($row = $result->fetch())
		{
			$rows[] = [
				'id' => (int)$row['ID'],
				'parentId' => (int)$row['PARENT_ID'],
				'collectionId' => (int)$row['COLLECTION_ID'],
			];
		}

		return $rows;
	}

	/**
	 * @param array<int, array{id: int, collectionId: int}> $batch
	 * @param array<int, int> $parentByChild
	 * @param array<int, string> $accessCodes
	 * @return array<int, true> parent ids that own at least one visible child in this batch
	 */
	private function probeVisibleParents(array $batch, array $parentByChild, array $accessCodes, int $userId): array
	{
		if (empty($batch))
		{
			return [];
		}

		$levels = DocumentAccessService::batchGetEffectiveLevels($batch, $accessCodes, $userId);
		$answered = [];
		foreach ($batch as $doc)
		{
			$childId = (int)$doc['id'];
			if (($levels[$childId] ?? DocumentAccessService::LEVEL_NONE) >= DocumentAccessService::LEVEL_VIEW)
			{
				$answered[$parentByChild[$childId]] = true;
			}
		}

		return $answered;
	}

	/**
	 * @param array<int, array{id: int, collectionId: int, parentId: int, title: string, position: int}> $keptNodes
	 * @param array<int, true> $parentInSet
	 * @param array<int, true> $hasChildrenMap
	 * @param array<int, true> $favoriteIds
	 * @return array<int, array{collectionId: int, title: string, nodes: array<int, array{id: int, parentId: int|null, title: string, position: int, hasChildren: bool, isFavorite: bool}>}>
	 */
	private function groupIntoContainers(
		array $keptNodes,
		array $parentInSet,
		array $hasChildrenMap,
		array $favoriteIds,
	): array
	{
		$collectionIds = [];
		foreach ($keptNodes as $node)
		{
			$collectionIds[(int)$node['collectionId']] = true;
		}
		$titles = $this->resolveCollectionTitles(array_keys($collectionIds));

		// Insertion order follows the (COLLECTION_ID ASC) page order, so container order is stable.
		$byCollection = [];
		foreach ($keptNodes as $node)
		{
			$cid = (int)$node['collectionId'];
			$id = (int)$node['id'];
			$parentId = (int)$node['parentId'];
			$byCollection[$cid][] = [
				'id' => $id,
				'parentId' => ($parentId > 0 && isset($parentInSet[$parentId])) ? $parentId : null,
				'title' => (string)$node['title'],
				// Same POSITION the collection tree sorts by, so both trees order branches alike.
				'position' => (int)($node['position'] ?? 0),
				'hasChildren' => isset($hasChildrenMap[$id]),
				'isFavorite' => isset($favoriteIds[$id]),
			];
		}

		$containers = [];
		foreach ($byCollection as $cid => $nodes)
		{
			$containers[] = [
				'collectionId' => (int)$cid,
				'title' => (string)($titles[$cid] ?? ''),
				'nodes' => $nodes,
			];
		}

		return $containers;
	}

	/**
	 * [TPL-01] Star flag of the page: one batch read for every node, never one read per node.
	 *
	 * @param int[] $documentIds
	 * @return array<int, true>
	 */
	private function resolveFavoriteIds(int $userId, array $documentIds): array
	{
		return array_flip((new FavoriteRepository())->findFavoriteEntityIds(
			$userId,
			FavoriteTable::ENTITY_TYPE_DOCUMENT,
			$documentIds,
		));
	}

	/**
	 * @param int[] $ids
	 * @return array<int, string>
	 */
	private function resolveCollectionTitles(array $ids): array
	{
		$ids = array_values(array_filter($ids, static fn(int $id): bool => $id > 0));
		if ($ids === [])
		{
			return [];
		}

		$rows = CollectionTable::query()
			->setSelect(['ID', 'NAME'])
			->whereIn('ID', $ids)
			->setCacheTtl(60)
			->exec()
		;

		$out = [];
		while ($row = $rows->fetch())
		{
			$out[(int)$row['ID']] = (string)($row['NAME'] ?? '');
		}

		return $out;
	}
}
