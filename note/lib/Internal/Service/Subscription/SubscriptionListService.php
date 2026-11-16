<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Subscription;

use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Note\Internal\Access\PortalAdmin;
use Bitrix\Note\Internal\Access\Service\CollectionAccessService;
use Bitrix\Note\Internal\Access\Service\DocumentAccessService;
use Bitrix\Note\Internal\Model\DocumentTable;
use Bitrix\Note\Internal\Model\FavoriteTable;
use Bitrix\Note\Internal\Model\SubscriptionTable;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Repository\FavoriteRepository;
use Bitrix\Note\Internal\Repository\SubscriptionRepository;
use Bitrix\Note\Internal\Service\Document\MainDocumentFilter;
use Bitrix\Note\Internal\Service\RecycleBin\RecycleBinFilter;
use Bitrix\Note\Internal\Service\Sidebar\AccessibleTreeService;

/**
 * One page of the articles a user subscribed to themselves: keyset window over (ID DESC) already
 * filtered by rights in the WHERE, plus batch notification coverage and the batch chevron hint.
 *
 * A read path of its own rather than a mode of the favorites list: the two lists share neither the
 * order (there is no manual position here), nor the row set, nor the archive rule: an archived
 * article stays in favorites and drops out of this one. What they do share is the meaning of a row
 * in the sidebar, so metadata, coverage and the chevron are assembled the same way.
 *
 * The list holds documents only, and every one of them is judged in the query, so nothing is dropped
 * after the fetch and the refill loop the favorites list needs has no counterpart here.
 *
 * The list is personal and must reflect current rights on every request: nothing here outlives it.
 */
final class SubscriptionListService
{
	// Deliberately its own pair of numbers, equal to the favorites ones: reusing that list's
	// constants would tie the page size of two lists that are free to diverge.
	public const DEFAULT_LIMIT = 50;
	public const MAX_LIMIT = 200;
	public const EXPAND_VIA_TREE = 'tree';
	public const EXPAND_VIA_ACCESSIBLE_TREE = 'accessibleTree';

	public function __construct(
		private readonly SubscriptionRepository $subscriptionRepository = new SubscriptionRepository(),
		private readonly DocumentRepository $documentRepository = new DocumentRepository(),
		// The star of a row: this list is built on a different fact than the favorites one, so whether
		// the article is also in favorites has to be asked for - one batch query per page.
		private readonly FavoriteRepository $favoriteRepository = new FavoriteRepository(),
		// The module's only copy of the coverage rule: the `notify` block is asked for, never
		// assembled here out of the row's own mode.
		private readonly CoverageResolver $coverageResolver = new CoverageResolver(),
		// Owner of the branch a shared row expands into, and therefore of the answer about its chevron.
		private readonly AccessibleTreeService $accessibleTreeService = new AccessibleTreeService(),
	) {}

	/**
	 * One page of subscribed articles the user may actually see. The end of the list is
	 * `nextCursor === null` and never the number of returned rows: a document that vanished between
	 * the window and the metadata read leaves the page short.
	 *
	 * @param array<int, string> $accessCodes access codes of $userId (same-user invariant)
	 * @param array{id: int}|null $afterCursor
	 * @return array{
	 *   items: array<int, array{
	 *     id: int, entityType: string, entityId: int, title: string,
	 *     collectionId: int, parentId: int|null, hasChildren: bool, expandVia: string, isFavorite: bool,
	 *     notify: array{mode: ?string, subscribed: bool, muted: bool, inherited: bool, inheritedSource: ?string, notified: bool}
	 *   }>,
	 *   nextCursor: array{id: int}|null
	 * }
	 */
	public function listPage(int $userId, array $accessCodes, int $limit, ?array $afterCursor = null): array
	{
		if ($userId <= 0)
		{
			return ['items' => [], 'nextCursor' => null];
		}

		$limit = min(self::MAX_LIMIT, max(1, $limit));
		$isAdmin = PortalAdmin::isAdmin($userId);
		$collectionLevels = $isAdmin ? [] : $this->resolveCollectionLevels($accessCodes);

		// +1 lookahead answers "is there another page" without a COUNT query.
		$window = $this->subscriptionRepository->pageAfter(
			$userId,
			$this->parseCursor($afterCursor),
			$limit + 1,
			$this->buildVisibilityFilter($isAdmin, $accessCodes, $collectionLevels),
		);
		if (empty($window))
		{
			return ['items' => [], 'nextCursor' => null];
		}

		$rows = array_slice($window, 0, $limit);
		$nextCursor = count($window) > $limit
			? ['id' => (int)$rows[count($rows) - 1]['id']]
			: null
		;

		$visible = $this->hydrate($rows);
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
	 * Whether the user has at least one row this list would show: the sidebar offers the
	 * notifications mode on this answer alone, so it is judged by the very predicate the page uses.
	 *
	 * @param array<int, string> $accessCodes access codes of $userId (same-user invariant)
	 */
	public function hasAnySubscription(int $userId, array $accessCodes): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		// Asked on every open of the editor, and answered "no" for everyone who follows nothing - which
		// is most of the portal. So the cheap half goes first: one lookup on the subscription's own index
		// against the collection levels below, which are a read over every collection the user may see.
		if (!$this->subscriptionRepository->hasOwnDocumentSubscription($userId))
		{
			return false;
		}

		$isAdmin = PortalAdmin::isAdmin($userId);
		$collectionLevels = $isAdmin ? [] : $this->resolveCollectionLevels($accessCodes);

		return $this->subscriptionRepository->hasDeliverableSubscription(
			$userId,
			$this->buildVisibilityFilter($isAdmin, $accessCodes, $collectionLevels),
		);
	}

	/**
	 * @param array{id: int}|null $afterCursor
	 */
	private function parseCursor(?array $afterCursor): ?int
	{
		$id = isset($afterCursor['id']) ? (int)$afterCursor['id'] : 0;

		return $id > 0 ? $id : null;
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
	 * Visibility predicate over the joined document, or null for a portal admin (who sees every
	 * article). One rule, one owner: the predicate itself comes from the access layer, this only
	 * names the fields of the joined alias it applies to.
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

		return DocumentAccessService::buildListVisibilityFilter(
			'DOCUMENT.ID',
			'DOCUMENT.COLLECTION_ID',
			DocumentAccessService::personalCodes($accessCodes),
			DocumentAccessService::accessibleCollectionIds($collectionLevels),
		);
	}

	/**
	 * Document metadata of the window in one query. Rows arrive already filtered by the predicate
	 * above, so this attaches titles and shape and does NOT decide visibility: it only drops a row
	 * whose document vanished between the two queries.
	 *
	 * @param array<int, array{id: int, entityId: int}> $rows
	 * @return array<int, array<string, mixed>> subscription row + document metadata
	 */
	private function hydrate(array $rows): array
	{
		$meta = $this->documentMeta(array_map(static fn(array $row): int => (int)$row['entityId'], $rows));

		$visible = [];
		foreach ($rows as $row)
		{
			$documentMeta = $meta[(int)$row['entityId']] ?? null;
			if ($documentMeta !== null)
			{
				$visible[] = $row + $documentMeta;
			}
		}

		return $visible;
	}

	/**
	 * @param int[] $documentIds
	 * @return array<int, array{title: string, collectionId: int, parentId: int|null}>
	 */
	private function documentMeta(array $documentIds): array
	{
		$query = DocumentTable::query()
			->setSelect(['ID', 'COLLECTION_ID', 'PARENT_ID', 'TITLE'])
			->whereIn('ID', $documentIds)
			->where('IS_ARCHIVED', 'N')
		;
		(new RecycleBinFilter())->applyExclusion($query);
		(new MainDocumentFilter())->applyExclusion($query);

		$meta = [];
		$result = $query->exec();
		while ($row = $result->fetch())
		{
			$parentId = isset($row['PARENT_ID']) ? (int)$row['PARENT_ID'] : 0;
			$meta[(int)$row['ID']] = [
				'title' => (string)($row['TITLE'] ?? ''),
				'collectionId' => (int)($row['COLLECTION_ID'] ?? 0),
				'parentId' => $parentId > 0 ? $parentId : null,
			];
		}

		return $meta;
	}

	/**
	 * Coverage of the page in one CoverageResolver call. Nothing is dropped here: every row of this
	 * list carries a direct `self` subscription by construction, so the block describes the row
	 * rather than deciding whether it belongs.
	 *
	 * @param array<int, array<string, mixed>> $rows
	 * @return array<int, array<string, mixed>> rows with the `notify` state attached
	 */
	private function attachNotifyState(int $userId, array $rows): array
	{
		$items = [];
		foreach ($rows as $row)
		{
			$items[(int)$row['entityId']] = (int)$row['collectionId'];
		}

		$states = $this->coverageResolver->resolve($userId, $items);

		$withState = [];
		foreach ($rows as $row)
		{
			$row['notify'] = $states[(int)$row['entityId']] ?? [
				'mode' => null,
				'subscribed' => false,
				'muted' => false,
				'inherited' => false,
				'inheritedSource' => null,
				'notified' => false,
			];
			$withState[] = $row;
		}

		return $withState;
	}

	/**
	 * DTO-01 rows. The chevron is answered by one query over every knowledge base of the page;
	 * structure only: a descendant's own rights are not checked (ADR section 3 trade-off).
	 *
	 * @param array<int, array<string, mixed>> $rows
	 * @param array<int, int> $collectionLevels
	 * @param array<int, string> $accessCodes access codes of $userId (same-user invariant)
	 * @return array<int, array<string, mixed>>
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
		foreach ($rows as $row)
		{
			$documentIdsByCollection[(int)$row['collectionId']][] = (int)$row['entityId'];
		}

		$documentIds = array_merge(...array_values($documentIdsByCollection));

		// One query for the page, the same batch source the tree reads its stars from. Membership of
		// the favorites is a fact of its own since the split, so it has to be asked for: a row of this
		// list is here because of a subscription and says nothing about the star by itself.
		$favoriteIds = array_flip($this->favoriteRepository->findFavoriteEntityIds(
			$userId,
			FavoriteTable::ENTITY_TYPE_DOCUMENT,
			$documentIds,
		));

		// One query for every knowledge base of the page: the repository answers with flat pairs and
		// the grouping by knowledge base stays here, so a parent of another base cannot leak in.
		$pairs = $this->documentRepository->getChildParentPairsByCollections(
			array_keys($documentIdsByCollection),
			$documentIds,
		);

		$withChildren = [];
		foreach ($pairs as $pair)
		{
			$withChildren[$pair['collectionId']][$pair['parentId']] = true;
		}

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
			$collectionId = (int)$row['collectionId'];

			// One decision feeds both fields: the namespace the row expands through is also the one
			// that answers whether there is anything to expand.
			$viaTree = $this->expandsViaTree($collectionId, $isAdmin, $collectionLevels);

			$items[] = [
				'id' => (int)$row['id'],
				// Constant by construction: the list holds documents only. Kept so the client
				// normalises a row of either sidebar list with one mapper.
				'entityType' => SubscriptionTable::SCOPE_DOCUMENT,
				'entityId' => $entityId,
				'title' => (string)$row['title'],
				'collectionId' => $collectionId,
				'parentId' => $row['parentId'] !== null ? (int)$row['parentId'] : null,
				'hasChildren' => $viaTree
					? isset($withChildren[$collectionId][$entityId])
					: isset($accessibleHasChildren[$entityId])
				,
				'expandVia' => $viaTree ? self::EXPAND_VIA_TREE : self::EXPAND_VIA_ACCESSIBLE_TREE,
				'isFavorite' => isset($favoriteIds[$entityId]),
				'notify' => $row['notify'],
			];
		}

		return $items;
	}

	/**
	 * Does this reader open the document's branch in the regular tree (that is, may they see the
	 * whole collection), or through the pruned accessible tree of their personal grants.
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
	 * Chevron of the rows that expand through the accessible tree: inside a collection the reader
	 * may not see, the branch holds nothing but children granted to them personally, so the question
	 * is put to the namespace that will serve the expansion.
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
			if (!$this->expandsViaTree((int)$row['collectionId'], false, $collectionLevels))
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
}
