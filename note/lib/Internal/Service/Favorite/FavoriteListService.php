<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Favorite;

use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Note\Internal\Access\PortalAdmin;
use Bitrix\Note\Internal\Access\Service\CollectionAccessService;
use Bitrix\Note\Internal\Access\Service\DocumentAccessService;
use Bitrix\Note\Internal\Model\CollectionTable;
use Bitrix\Note\Internal\Model\DocumentTable;
use Bitrix\Note\Internal\Model\FavoriteTable;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Repository\FavoriteRepository;
use Bitrix\Note\Internal\Service\Document\MainDocumentFilter;
use Bitrix\Note\Internal\Service\RecycleBin\RecycleBinFilter;
use Bitrix\Note\Internal\Service\Sidebar\AccessibleTreeService;
use Bitrix\Note\Internal\Service\Subscription\CoverageResolver;

/**
 * One page of a user's favorites: keyset window over (POSITION DESC, ID DESC)
 * already filtered by rights in the WHERE, plus batch notification coverage and the batch chevron
 * hint.
 *
 * The list is mixed — a row points at a document or at a knowledge base — so the predicate has two
 * branches: documents are judged through the joined document, knowledge bases against the id list
 * the collection layer resolves. Rights used to be applied row by row after the fetch, which forced
 * a refill loop; on a sparse ACL the budget ran out before the first visible row and the page came
 * back empty. Now a row that reaches PHP is visible by construction and one window answers the page.
 *
 * The list is personal and must reflect current rights on every request, so nothing here outlives
 * the request. What is left per row is answered by batch calls: coverage by CoverageResolver (the
 * module's only copy of the inheritance rule), the chevron by one query over every knowledge base
 * of the page.
 */
final class FavoriteListService
{
	public const DEFAULT_LIMIT = 50;
	// Same upper bound the batch state read uses.
	public const MAX_LIMIT = 200;
	public const EXPAND_VIA_TREE = 'tree';
	public const EXPAND_VIA_ACCESSIBLE_TREE = 'accessibleTree';

	public function __construct(
		private readonly FavoriteRepository $favoriteRepository = new FavoriteRepository(),
		private readonly DocumentRepository $documentRepository = new DocumentRepository(),
		private readonly CoverageResolver $coverageResolver = new CoverageResolver(),
		// Owner of the branch a shared row expands into, and therefore of the answer about its chevron.
		private readonly AccessibleTreeService $accessibleTreeService = new AccessibleTreeService(),
	) {}

	/**
	 * One page of rows the user may actually see. Rows whose object is gone, trashed or no
	 * longer accessible are skipped silently - the favorite row itself survives, so restoring the
	 * object or the access brings it back on its old position.
	 *
	 * @param array<int, string> $accessCodes access codes of $userId (same-user invariant)
	 * @param array{position: int, id: int}|null $afterCursor
	 * @return array{
	 *   items: array<int, array{
	 *     id: int, entityType: string, entityId: int, title: string, position: int,
	 *     collectionId: int, parentId: int|null, hasChildren: bool, expandVia: string,
	 *     notify: array{mode: ?string, subscribed: bool, muted: bool, inherited: bool, inheritedSource: ?string, notified: bool}
	 *   }>,
	 *   nextCursor: array{position: int, id: int}|null
	 * }
	 */
	public function listPage(int $userId, array $accessCodes, int $limit, ?array $afterCursor = null): array
	{
		$empty = ['items' => [], 'nextCursor' => null];
		if ($userId <= 0)
		{
			return $empty;
		}

		$limit = min(self::MAX_LIMIT, max(1, $limit));
		$isAdmin = PortalAdmin::isAdmin($userId);
		$collectionLevels = $isAdmin ? [] : $this->resolveCollectionLevels($accessCodes);

		[$afterPosition, $afterId] = $this->parseCursor($afterCursor);

		// +1 lookahead answers "is there another page" without a COUNT query.
		$window = $this->favoriteRepository->pageAfter(
			$userId,
			$afterPosition,
			$afterId,
			$limit + 1,
			$this->buildVisibilityFilter($isAdmin, $accessCodes, $collectionLevels),
		);
		if (empty($window))
		{
			return $empty;
		}

		$candidates = array_slice($window, 0, $limit);

		// The cursor advances by the last CANDIDATE, not by the last row returned: hydrate() may drop
		// one whose object vanished between the two queries, and that row must be walked past rather
		// than re-offered on the next page.
		$nextCursor = count($window) > $limit
			? ['position' => (int)$candidates[count($candidates) - 1]['position'], 'id' => (int)$candidates[count($candidates) - 1]['id']]
			: null
		;

		$visible = $this->hydrate($candidates);
		if (empty($visible))
		{
			return ['items' => [], 'nextCursor' => $nextCursor];
		}

		return [
			'items' => $this->buildItems(
				$this->attachNotifyState($userId, $visible),
				$isAdmin,
				$collectionLevels,
				$accessCodes,
				$userId,
			),
			'nextCursor' => $nextCursor,
		];
	}

	/**
	 * One row of the same shape a page carries, for callers that already know which object
	 * changed (a fresh add) and would otherwise re-read the whole first page.
	 *
	 * Runs the page pipeline over a single candidate, so visibility, coverage and the chevron follow
	 * exactly the same rules; `null` means the favorite row is missing or its object is not something
	 * this user may see, and the caller has to fall back on re-reading the list.
	 *
	 * @param array<int, string> $accessCodes access codes of $userId (same-user invariant)
	 * @return array<string, mixed>|null
	 */
	public function buildRow(int $userId, array $accessCodes, string $entityType, int $entityId): ?array
	{
		if ($userId <= 0 || $entityId <= 0)
		{
			return null;
		}

		$isAdmin = PortalAdmin::isAdmin($userId);
		$collectionLevels = $isAdmin ? [] : $this->resolveCollectionLevels($accessCodes);

		// Same predicate the page uses, applied to one row: a single-row path that judged visibility
		// on its own would be the second copy of "what is visible" this design exists to avoid.
		$stored = $this->favoriteRepository->findVisibleRow(
			$userId,
			$entityType,
			$entityId,
			$this->buildVisibilityFilter($isAdmin, $accessCodes, $collectionLevels),
		);
		if ($stored === null)
		{
			return null;
		}

		$candidates = [[
			'id' => $stored['id'],
			'entityType' => $entityType,
			'entityId' => $entityId,
			'position' => $stored['position'],
		]];

		$visible = $this->hydrate($candidates);
		if (empty($visible))
		{
			return null;
		}

		$items = $this->buildItems(
			$this->attachNotifyState($userId, $visible),
			$isAdmin,
			$collectionLevels,
			$accessCodes,
			$userId,
		);

		return $items[0] ?? null;
	}

	/**
	 * @param array{position: int, id: int}|null $afterCursor
	 * @return array{0: int|null, 1: int|null}
	 */
	private function parseCursor(?array $afterCursor): array
	{
		$position = isset($afterCursor['position']) ? (int)$afterCursor['position'] : null;
		$id = isset($afterCursor['id']) ? (int)$afterCursor['id'] : null;
		if ($id === null || $id <= 0 || $position === null || $position < 0)
		{
			return [null, null];
		}

		return [$position, $id];
	}

	/**
	 * @param array<int, string> $accessCodes
	 * @return array<int, int> collectionId => effective level
	 */
	private function resolveCollectionLevels(array $accessCodes): array
	{
		$levels = CollectionAccessService::getAllUserLevels($accessCodes)['effective'] ?? [];

		$normalized = [];
		foreach ($levels as $collectionId => $level)
		{
			$normalized[(int)$collectionId] = (int)$level;
		}

		return $normalized;
	}

	/**
	 * Visibility predicate of the mixed list, or null for a portal admin (who sees every
	 * favorite). Document rows are judged through the joined document, collection rows against the
	 * PHP-resolved id list — the same two branches the two filters used to apply row by row.
	 *
	 * The admin bypass has to be explicit here. It used to arrive implicitly, from inside
	 * {@see DocumentAccessService::batchGetEffectiveLevels()}, which no longer runs on this path;
	 * the $isAdmin flag itself only ever gated the collection branch.
	 *
	 * @param array<int, string> $accessCodes
	 * @param array<int, int> $collectionLevels
	 */
	private function buildVisibilityFilter(bool $isAdmin, array $accessCodes, array $collectionLevels): ?ConditionTree
	{
		if ($isAdmin)
		{
			return null;
		}

		$accessibleCollectionIds = DocumentAccessService::accessibleCollectionIds($collectionLevels);
		$codesPersonal = DocumentAccessService::personalCodes($accessCodes);

		// A document row must also be alive: the main document is not a list entry, a trashed one has
		// no place here, and a row whose document is gone joins to nothing (DOCUMENT.ID IS NULL).
		$documentBranch = Query::filter()
			->where('ENTITY_TYPE', FavoriteTable::ENTITY_TYPE_DOCUMENT)
			->whereNotNull('DOCUMENT.ID')
			->whereNull('DOCUMENT.RECYCLE_BIN.ID')
			->where('DOCUMENT.IS_MAIN', DocumentTable::IS_MAIN_NO)
			->where(DocumentAccessService::buildListVisibilityFilter(
				'DOCUMENT.ID',
				'DOCUMENT.COLLECTION_ID',
				$codesPersonal,
				$accessibleCollectionIds,
				// An archived base is readable, and favorites have never hidden its documents:
				// the allowance keeps that. A deleted base is another matter - its content has no place
				// on a live surface.
				[DocumentAccessService::STATE_ARCHIVED],
			))
		;

		$filter = Query::filter()->logic('or')->where($documentBranch);

		if (!empty($accessibleCollectionIds))
		{
			$filter->where(
				Query::filter()
					->where('ENTITY_TYPE', FavoriteTable::ENTITY_TYPE_COLLECTION)
					->whereIn('ENTITY_ID', $accessibleCollectionIds),
			);
		}

		return $filter;
	}

	/**
	 * Object metadata of one window, batched per entity type. Rows arrive already filtered by the
	 * predicate above, so this only attaches titles and shape — it does NOT decide visibility.
	 * A row still drops out when its object vanished between the two queries.
	 *
	 * @param array<int, array{id: int, entityType: string, entityId: int, position: int}> $candidates
	 * @return array<int, array<string, mixed>> enriched rows (favorite row + object metadata)
	 */
	private function hydrate(array $candidates): array
	{
		$documentIds = [];
		$collectionIds = [];
		foreach ($candidates as $row)
		{
			if ($row['entityType'] === FavoriteTable::ENTITY_TYPE_DOCUMENT)
			{
				$documentIds[] = (int)$row['entityId'];
			}
			elseif ($row['entityType'] === FavoriteTable::ENTITY_TYPE_COLLECTION)
			{
				$collectionIds[] = (int)$row['entityId'];
			}
		}

		$documents = empty($documentIds) ? [] : $this->documentMeta($documentIds);
		$collections = empty($collectionIds) ? [] : $this->collectionMeta($collectionIds);

		$visible = [];
		foreach ($candidates as $row)
		{
			$entityId = (int)$row['entityId'];
			$meta = $row['entityType'] === FavoriteTable::ENTITY_TYPE_DOCUMENT
				? ($documents[$entityId] ?? null)
				: ($collections[$entityId] ?? null)
			;
			if ($meta === null)
			{
				continue;
			}

			$visible[] = $row + $meta;
		}

		return $visible;
	}

	/**
	 * Metadata of alive, non-trashed documents. The recycle bin and the main document are excluded
	 * by the same filters every listing uses — kept here so a row whose document was trashed
	 * between the page query and this one still disappears. Deleting a knowledge base puts its whole
	 * tree in the bin, so the same exclusion answers for the documents of a deleted base.
	 *
	 * @param int[] $documentIds
	 * @return array<int, array{title: string, collectionId: int, parentId: int|null}>
	 */
	private function documentMeta(array $documentIds): array
	{
		$query = DocumentTable::query()
			->setSelect(['ID', 'COLLECTION_ID', 'PARENT_ID', 'TITLE'])
			->whereIn('ID', $documentIds)
		;
		(new RecycleBinFilter())->applyExclusion($query);
		(new MainDocumentFilter())->applyExclusion($query);

		$rows = [];
		$result = $query->exec();
		while ($row = $result->fetch())
		{
			$id = (int)$row['ID'];
			$parentId = isset($row['PARENT_ID']) ? (int)$row['PARENT_ID'] : 0;
			$rows[$id] = [
				'title' => (string)($row['TITLE'] ?? ''),
				'collectionId' => (int)($row['COLLECTION_ID'] ?? 0),
				'parentId' => $parentId > 0 ? $parentId : null,
			];
		}

		return $rows;
	}

	/**
	 * A base in the recycle bin drops out of the page here, not by its favorite row being deleted:
	 * restoring the base brings the row back on its old position by itself.
	 *
	 * @param int[] $collectionIds
	 * @return array<int, array{title: string, collectionId: int, parentId: null, collectionArchived: bool}>
	 */
	private function collectionMeta(array $collectionIds): array
	{
		$rows = CollectionTable::query()
			->setSelect(['ID', 'NAME', 'IS_ARCHIVED'])
			->whereIn('ID', $collectionIds)
			->whereNull('TRASHED_AT')
			->exec()
		;

		$visible = [];
		while ($row = $rows->fetch())
		{
			$id = (int)$row['ID'];

			$visible[$id] = [
				'title' => (string)($row['NAME'] ?? ''),
				'collectionId' => $id,
				'parentId' => null,
				'collectionArchived' => ($row['IS_ARCHIVED'] ?? 'N') === 'Y',
			];
		}

		return $visible;
	}

	/**
	 * Coverage of one page: one CoverageResolver call per entity type, never per row. Describes the
	 * row, never decides whether it belongs: the page is the user's whole list, subscribed or not.
	 *
	 * @param array<int, array<string, mixed>> $rows
	 * @return array<int, array<string, mixed>> rows with the `notify` state attached
	 */
	private function attachNotifyState(int $userId, array $rows): array
	{
		if (empty($rows))
		{
			return [];
		}

		$documentItems = [];
		$collectionIds = [];
		foreach ($rows as $row)
		{
			if ($row['entityType'] === FavoriteTable::ENTITY_TYPE_DOCUMENT)
			{
				$documentItems[(int)$row['entityId']] = (int)$row['collectionId'];
			}
			else
			{
				$collectionIds[] = (int)$row['entityId'];
			}
		}

		$documentStates = empty($documentItems) ? [] : $this->coverageResolver->resolve($userId, $documentItems);
		$collectionStates = empty($collectionIds) ? [] : $this->coverageResolver->resolveCollections($userId, $collectionIds);

		$withState = [];
		foreach ($rows as $row)
		{
			$entityId = (int)$row['entityId'];
			$state = $row['entityType'] === FavoriteTable::ENTITY_TYPE_DOCUMENT
				? ($documentStates[$entityId] ?? null)
				: ($collectionStates[$entityId] ?? null)
			;
			$state ??= [
				'mode' => null,
				'subscribed' => false,
				'muted' => false,
				'inherited' => false,
				'inheritedSource' => null,
				'notified' => false,
			];

			$row['notify'] = $state;
			$withState[] = $row;
		}

		return $withState;
	}

	/**
	 * Chevron hint of the page: documents are answered by one query over all knowledge bases
	 * present on the page, knowledge bases by a single "has live root documents" query. Structure
	 * only - a descendant's own rights are not checked (ADR section 3 trade-off).
	 *
	 * An archived knowledge base never gets a chevron: DTO-01 carries no archived flag, so the
	 * client's "archived rows do not expand" rule rides on this hint alone.
	 *
	 * @param array<int, array<string, mixed>> $rows
	 * @param array<int, int> $collectionLevels
	 * @param array<int, string> $accessCodes access codes of $userId (same-user invariant)
	 * @return array<int, array<string, mixed>> DTO-01 rows
	 */
	private function buildItems(
		array $rows,
		bool $isAdmin,
		array $collectionLevels,
		array $accessCodes,
		int $userId,
	): array
	{
		$documentIdsByCollection = [];
		$collectionIds = [];
		foreach ($rows as $row)
		{
			if ($row['entityType'] === FavoriteTable::ENTITY_TYPE_DOCUMENT)
			{
				$documentIdsByCollection[(int)$row['collectionId']][] = (int)$row['entityId'];
			}
			else
			{
				$collectionIds[] = (int)$row['entityId'];
			}
		}

		// One query for every knowledge base of the page: the repository answers with flat pairs and
		// the grouping by knowledge base stays here, so a parent of another base cannot leak in.
		$documentHasChildren = [];
		if (!empty($documentIdsByCollection))
		{
			$pairs = $this->documentRepository->getChildParentPairsByCollections(
				array_keys($documentIdsByCollection),
				array_merge(...array_values($documentIdsByCollection)),
			);

			$withChildren = [];
			foreach ($pairs as $pair)
			{
				$withChildren[$pair['collectionId']][$pair['parentId']] = true;
			}

			foreach ($documentIdsByCollection as $collectionId => $ids)
			{
				foreach ($ids as $id)
				{
					$documentHasChildren[(int)$id] = isset($withChildren[(int)$collectionId][(int)$id]);
				}
			}
		}
		$collectionHasRoots = $this->resolveCollectionsWithRootDocuments($collectionIds);
		$accessibleHasChildren = $this->resolveAccessibleHasChildren(
			$rows,
			$isAdmin,
			$collectionLevels,
			$accessCodes,
			$userId,
		);

		$items = [];
		foreach ($rows as $row)
		{
			$entityId = (int)$row['entityId'];
			$isDocument = ($row['entityType'] === FavoriteTable::ENTITY_TYPE_DOCUMENT);
			$collectionId = (int)$row['collectionId'];

			if ($isDocument)
			{
				// One decision feeds both fields: the namespace the row expands through is also the one
				// that answers whether there is anything to expand. Deciding them apart is what put a
				// chevron on a shared row over children the reader cannot see.
				$viaTree = $this->expandsViaTree($collectionId, $isAdmin, $collectionLevels);
				$expandVia = $viaTree ? self::EXPAND_VIA_TREE : self::EXPAND_VIA_ACCESSIBLE_TREE;
				$hasChildren = $viaTree
					? ($documentHasChildren[$entityId] ?? false)
					: isset($accessibleHasChildren[$entityId])
				;
			}
			else
			{
				$hasChildren = !($row['collectionArchived'] ?? false) && isset($collectionHasRoots[$entityId]);
				// A knowledge base is only ever reachable through the regular tree.
				$expandVia = self::EXPAND_VIA_TREE;
			}

			$items[] = [
				'id' => (int)$row['id'],
				'entityType' => (string)$row['entityType'],
				'entityId' => $entityId,
				'title' => (string)$row['title'],
				'position' => (int)$row['position'],
				'collectionId' => $collectionId,
				'parentId' => $row['parentId'] !== null ? (int)$row['parentId'] : null,
				'hasChildren' => $hasChildren,
				'expandVia' => $expandVia,
				'notify' => $row['notify'],
			];
		}

		return $items;
	}

	/**
	 * Does this reader open the document's branch in the regular tree — that is, may they see the
	 * whole collection — or through the pruned accessible tree of their personal grants.
	 *
	 * @param array<int, int> $collectionLevels
	 */
	private function expandsViaTree(int $collectionId, bool $isAdmin, array $collectionLevels): bool
	{
		return $isAdmin
			|| ($collectionLevels[$collectionId] ?? CollectionAccessService::LEVEL_NONE) >= CollectionAccessService::LEVEL_VIEW
		;
	}

	/**
	 * Chevron of the rows that expand through the accessible tree. The batch query above
	 * answers whether the document has live children at all, which is the right answer only inside a
	 * collection the reader may see: with a document-only grant the branch holds nothing but children
	 * granted to them personally, and a grant of `scope=document` reaches none. So the question is put
	 * to the namespace that will serve the expansion, and only for the rows that need it.
	 *
	 * @param array<int, array<string, mixed>> $rows
	 * @param array<int, int> $collectionLevels
	 * @param array<int, string> $accessCodes
	 * @return array<int, true> set of document ids owning an accessible child
	 */
	private function resolveAccessibleHasChildren(
		array $rows,
		bool $isAdmin,
		array $collectionLevels,
		array $accessCodes,
		int $userId,
	): array
	{
		if ($isAdmin)
		{
			// An admin reads every branch through the regular tree.
			return [];
		}

		$documentIds = [];
		foreach ($rows as $row)
		{
			if (
				$row['entityType'] === FavoriteTable::ENTITY_TYPE_DOCUMENT
				&& !$this->expandsViaTree((int)$row['collectionId'], false, $collectionLevels)
			)
			{
				$documentIds[] = (int)$row['entityId'];
			}
		}

		if (empty($documentIds))
		{
			return [];
		}

		return $this->accessibleTreeService->hasAccessibleChildren($documentIds, $accessCodes, $userId);
	}

	/**
	 * @param int[] $collectionIds
	 * @return array<int, true> knowledge bases owning at least one live root document
	 */
	private function resolveCollectionsWithRootDocuments(array $collectionIds): array
	{
		$normalizedIds = array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $collectionIds),
			static fn(int $id): bool => $id > 0,
		)));
		if (empty($normalizedIds))
		{
			return [];
		}

		// One DISTINCT over the live roots of the whole set: the set is bounded by the page size, so a
		// single query replaces one existence probe per knowledge base on the first render path.
		$query = DocumentTable::query()
			->setSelect(['COLLECTION_ID'])
			->whereIn('COLLECTION_ID', $normalizedIds)
			->whereNull('PARENT_ID')
			->where('IS_ARCHIVED', 'N')
			->setDistinct()
		;
		(new RecycleBinFilter())->applyExclusion($query);
		(new MainDocumentFilter())->applyExclusion($query);

		$withRoots = [];
		$result = $query->exec();
		while ($row = $result->fetch())
		{
			$collectionId = (int)($row['COLLECTION_ID'] ?? 0);
			if ($collectionId > 0)
			{
				$withRoots[$collectionId] = true;
			}
		}

		return $withRoots;
	}
}
