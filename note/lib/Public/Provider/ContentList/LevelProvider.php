<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Provider\ContentList;

use Bitrix\Note\Internal\Access\Service\CollectionAccessService;
use Bitrix\Note\Internal\Access\Service\DocumentAccessService;
use Bitrix\Note\Internal\Entity\Search\SearchResult;
use Bitrix\Note\Internal\Model\Document;
use Bitrix\Note\Internal\Repository\CollectionRepository;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Repository\DocumentSearchRepository;
use Bitrix\Note\Internal\Repository\RecycleBinRepository;
use Bitrix\Note\Internal\Service\Document\DocumentCardMetaResolver;
use Bitrix\Note\Internal\Service\Search\SearchQueryBuilder;
use Bitrix\Note\Internal\Service\User\AuthorResolver;
use Bitrix\Note\Public\Dto\ContentList\ContentState;
use Bitrix\Note\Public\Dto\ContentList\NodeDto;
use Bitrix\Note\Public\Dto\ContentList\PathSegmentDto;
use Bitrix\Note\Public\Provider\TreeProvider;

/**
 * Second phase of every content-area list: one level at a time, by cursor. The top level of
 * a section or the children of one node - never a recursion. Expanding a node pulls exactly one
 * level, so the price of opening a deep branch is paid a level at a time and only for what is
 * actually opened.
 *
 * The cursor is a plain keyset. For the bin and the archive it is inclusive: it names the first
 * row of the page it opens, so the value a section hands out for its first page and the value
 * nextCursor hands out for the following one mean the same thing. {@see getLiveLevel()} is the
 * exception - it reads through {@see TreeProvider}, whose cursor is exclusive and names the last
 * row already returned.
 */
final class LevelProvider
{
	public const DEFAULT_LIMIT = 50;
	public const MAX_LIMIT = 200;

	public function __construct(
		private readonly RecycleBinRepository $recycleBinRepository = new RecycleBinRepository(),
		private readonly DocumentRepository $documentRepository = new DocumentRepository(),
		private readonly CollectionRepository $collectionRepository = new CollectionRepository(),
		private readonly DocumentSearchRepository $searchRepository = new DocumentSearchRepository(),
		private readonly SearchQueryBuilder $searchQueryBuilder = new SearchQueryBuilder(),
		private readonly DocumentCardMetaResolver $cardMetaResolver = new DocumentCardMetaResolver(),
		private readonly AuthorResolver $authorResolver = new AuthorResolver(),
	) {}

	/**
	 * One level of the recycle bin: the top level of a section when $parentId is null, the children
	 * of a trashed node otherwise. A null $collectionId asks for the technical section, which is
	 * flat - an orphan's structure is torn, there is nothing to expand.
	 *
	 * @param array{changedAt: string, id: int}|null $cursor
	 * @return array{items: NodeDto[], nextCursor: array{changedAt: string, id: int}|null}
	 */
	public function getRecycleBinLevel(
		int $userId,
		?int $collectionId,
		?int $parentId,
		?array $cursor = null,
		int $limit = self::DEFAULT_LIMIT,
	): array
	{
		$limit = $this->normalizeLimit($limit);
		$isAdmin = DocumentAccessService::isPortalAdmin($userId);

		if ($parentId !== null && $parentId > 0)
		{
			$rows = $this->recycleBinRepository->listChildPage($parentId, $cursor, $limit + 1);
		}
		elseif ($collectionId === null)
		{
			$rows = $this->recycleBinRepository->listOrphanPage($isAdmin ? null : $userId, $cursor, $limit + 1);
		}
		else
		{
			$rows = $this->recycleBinRepository->listRootPage($collectionId, $cursor, $limit + 1);
		}

		[$rows, $nextCursor] = $this->splitPage($rows, $limit);

		return [
			'items' => $this->buildBinItems($userId, $rows, flat: $collectionId === null),
			'nextCursor' => $nextCursor,
		];
	}

	/**
	 * Top level of several sections at once - what opening the bin asks for. One pass over the rows of
	 * all the sections instead of a page read per section: the price of opening the list stopped
	 * growing with the number of knowledge bases the reader happens to have.
	 *
	 * A section the bounded window did not reach is absent from the answer instead of being guessed
	 * at, and the client asks for it the usual way.
	 *
	 * @param int[] $collectionIds bases whose bin this reader may see: live ones they hold, and ones
	 *        lying in the bin themselves - deleting a base no longer takes its row away, so its
	 *        section opens here like any other, at the one threshold a deleted base answers by
	 *        ({@see DocumentAccessService::canManageCollectionInRecycleBin()}). Only a base gone from
	 *        the table has no section: its documents are orphans and are read through the technical
	 *        one instead.
	 * @return array<int, array{items: NodeDto[], nextCursor: array{changedAt: string, id: int}|null}>
	 */
	public function getRecycleBinRootLevels(int $userId, array $collectionIds, int $limit = self::DEFAULT_LIMIT): array
	{
		$limit = $this->normalizeLimit($limit);
		$pages = $this->readGroupedPages(
			$collectionIds,
			$limit,
			fn(array $ids, int $window): array => $this->recycleBinRepository->listRootPagesByCollection($ids, $window),
		);
		if (empty($pages))
		{
			return [];
		}

		$items = $this->buildBinItems($userId, $this->mergeRows($pages), flat: false);

		return $this->assemblePages($pages, $items);
	}

	/**
	 * @param array<int, array{id: int, documentId: int, changedAt: string}> $rows
	 * @param bool $flat the technical section: an orphan's structure is torn, so nothing expands
	 * @return NodeDto[] in the order of the rows
	 */
	private function buildBinItems(int $userId, array $rows, bool $flat): array
	{
		if (empty($rows))
		{
			return [];
		}

		$documentIds = array_map(static fn(array $row): int => $row['documentId'], $rows);
		$documents = $this->indexById($this->documentRepository->getMetaByIds(
			$documentIds,
			['ID', 'TITLE', 'PARENT_ID', 'COLLECTION_ID', 'CREATED_BY'],
		));
		$cardMeta = $this->resolveCardMeta($documents);
		$childCounts = $this->recycleBinRepository->getChildCountMap(
			$this->collectionIdsOf($documents),
			$documentIds,
		);

		$entryIds = array_map(static fn(array $row): int => $row['id'], $rows);
		$acl = DocumentAccessService::bulkComputeRecycleBinAcl(
			$userId,
			array_values($this->recycleBinRepository->getByIds($entryIds)),
		);

		$items = [];
		foreach ($rows as $row)
		{
			$document = $documents[$row['documentId']] ?? null;
			if ($document === null)
			{
				continue;
			}

			$entryAcl = $acl[$row['id']] ?? ['canView' => false, 'canRestore' => false, 'canHardDelete' => false];
			if ($entryAcl['canView'] !== true)
			{
				continue;
			}

			$childCount = (int)($childCounts[$row['documentId']] ?? 0);
			$items[] = new NodeDto(
				documentId: $row['documentId'],
				title: (string)$document->getTitle(),
				excerpt: (string)($cardMeta[$row['documentId']]['excerpt'] ?? ''),
				authorId: (int)$document->getCreatedBy(),
				changedAt: $row['changedAt'],
				state: ContentState::Trashed,
				childCount: $childCount,
				// The technical section stays flat whatever the data says: part of an orphan's parents
				// went to the bin separately, part no longer exists, so the tree cannot be rebuilt.
				hasChildren: !$flat && $childCount > 0,
				canRestore: (bool)$entryAcl['canRestore'],
				canHardDelete: (bool)$entryAcl['canHardDelete'],
				recycleBinEntryId: $row['id'],
				author: $cardMeta[$row['documentId']]['author'] ?? null,
			);
		}

		return $items;
	}

	/**
	 * The knowledge base a level belongs to. A level inside a node is asked for by the node alone,
	 * and the base is what the index of the archive is keyed by, so it is worth one lookup by primary
	 * key to name it.
	 */
	private function resolveCollectionIdOfLevel(?int $collectionId, ?int $parentId): int
	{
		if ($collectionId !== null && $collectionId > 0)
		{
			return $collectionId;
		}

		if ($parentId === null || $parentId <= 0)
		{
			return 0;
		}

		$location = $this->documentRepository->getLocationById($parentId);

		return $location === null ? 0 : (int)$location['collectionId'];
	}

	/**
	 * One level of the archive. Same rule, other state: the children of an archived node are the
	 * archived documents directly under it.
	 *
	 * @param array{changedAt: string, id: int}|null $cursor
	 * @return array{items: NodeDto[], nextCursor: array{changedAt: string, id: int}|null}
	 */
	public function getArchiveLevel(
		int $userId,
		?int $collectionId,
		?int $parentId,
		?array $cursor = null,
		int $limit = self::DEFAULT_LIMIT,
	): array
	{
		$limit = $this->normalizeLimit($limit);

		if ($parentId !== null && $parentId > 0)
		{
			// Inside a node the level depends on how the reader got to the node. A reader of the base
			// sees the whole level; a reader who was let into this one document sees only what they
			// were let into as well - a grant on a document says nothing about its children, and a
			// subtree grant reaches them as grants of their own.
			$levelCollectionId = $this->resolveCollectionIdOfLevel($collectionId, $parentId);
			if (!$this->archiveReadable($levelCollectionId))
			{
				return ['items' => [], 'nextCursor' => null];
			}

			$rows = $this->holdsCollection($userId, $levelCollectionId)
				? $this->documentRepository->listArchivedChildPage($parentId, $levelCollectionId, $cursor, $limit + 1)
				: $this->documentRepository->listGrantedArchivedChildPage(
					$parentId,
					$levelCollectionId,
					DocumentAccessService::personalCodes(CollectionAccessService::buildUserAccessCodes($userId)),
					$cursor,
					$limit + 1,
				)
			;
		}
		elseif ($collectionId !== null && $collectionId > 0)
		{
			if (!$this->archiveReadable($collectionId))
			{
				return ['items' => [], 'nextCursor' => null];
			}

			$rows = $this->holdsCollection($userId, $collectionId)
				? $this->documentRepository->listArchivedRootPage($collectionId, $cursor, $limit + 1)
				: $this->documentRepository->listGrantedArchivedRootPage(
					$collectionId,
					DocumentAccessService::personalCodes(CollectionAccessService::buildUserAccessCodes($userId)),
					$cursor,
					$limit + 1,
				)
			;
		}
		else
		{
			return ['items' => [], 'nextCursor' => null];
		}

		[$rows, $nextCursor] = $this->splitPage($rows, $limit);

		return [
			'items' => $this->buildArchiveItems($userId, $rows),
			'nextCursor' => $nextCursor,
		];
	}

	/**
	 * Top level of several archive sections at once, a page per base: the order of an archive section
	 * runs on the index of its own base, so a pass over several of them at once would sort the
	 * archive of all of them to take one window - see {@see readPagesPerCollection()}.
	 *
	 * @param int[] $collectionIds
	 * @return array<int, array{items: NodeDto[], nextCursor: array{changedAt: string, id: int}|null}>
	 */
	public function getArchiveRootLevels(int $userId, array $collectionIds, int $limit = self::DEFAULT_LIMIT): array
	{
		$limit = $this->normalizeLimit($limit);
		$collectionIds = $this->dropTrashedCollections($collectionIds);
		if (empty($collectionIds))
		{
			return [];
		}

		// The sections of one archive can come from both reaches at once: the bases the reader holds,
		// and the ones they were let into a corner of. Read apart, because the starting point differs -
		// the top of the archived subtree there, the granted document itself here.
		[$heldIds, $grantedIds] = $this->splitByReach($userId, $collectionIds);
		$codesPersonal = empty($grantedIds)
			? []
			: DocumentAccessService::personalCodes(CollectionAccessService::buildUserAccessCodes($userId))
		;

		$pages = $this->readPagesPerCollection(
			$heldIds,
			$limit,
			fn(int $collectionId, int $window): array => $this->documentRepository
				->listArchivedRootPage($collectionId, null, $window),
		) + $this->readPagesPerCollection(
			$grantedIds,
			$limit,
			fn(int $collectionId, int $window): array => $this->documentRepository
				->listGrantedArchivedRootPage($collectionId, $codesPersonal, null, $window),
		);
		if (empty($pages))
		{
			return [];
		}

		$items = $this->buildArchiveItems($userId, $this->mergeRows($pages));

		return $this->assemblePages($pages, $items);
	}

	/**
	 * @param array<int, array{id: int, documentId: int, changedAt: string}> $rows
	 * @return NodeDto[] in the order of the rows
	 */
	private function buildArchiveItems(int $userId, array $rows): array
	{
		if (empty($rows))
		{
			return [];
		}

		$documentIds = array_map(static fn(array $row): int => $row['documentId'], $rows);
		$documents = $this->indexById($this->documentRepository->getMetaByIds(
			$documentIds,
			['ID', 'TITLE', 'PARENT_ID', 'COLLECTION_ID', 'CREATED_BY'],
		));
		$cardMeta = $this->resolveCardMeta($documents);
		$childCounts = $this->countArchivedChildren($userId, $documents);
		// Taking a document out of the archive is a right on its base, and one page may hold cards of
		// several bases, so the right is asked about all of them at once - a page of an archive of
		// twenty bases used to cost twenty pairs of queries.
		$canManageByCollection = CollectionAccessService::batchHasLevel(
			array_map(static fn(Document $document): int => (int)$document->getCollectionId(), $documents),
			$userId,
			CollectionAccessService::LEVEL_MANAGE,
		);

		$items = [];
		foreach ($rows as $row)
		{
			$document = $documents[$row['documentId']] ?? null;
			if ($document === null)
			{
				continue;
			}

			$documentCollectionId = (int)$document->getCollectionId();

			$childCount = (int)($childCounts[$row['documentId']] ?? 0);
			$items[] = new NodeDto(
				documentId: $row['documentId'],
				title: (string)$document->getTitle(),
				excerpt: (string)($cardMeta[$row['documentId']]['excerpt'] ?? ''),
				authorId: (int)$document->getCreatedBy(),
				changedAt: $row['changedAt'],
				state: ContentState::Archived,
				childCount: $childCount,
				hasChildren: $childCount > 0,
				canRestore: $canManageByCollection[$documentCollectionId] ?? false,
				// Deleting an archived document sends it to the bin; there is no hard delete on this
				// surface, and the card must not offer one.
				canHardDelete: false,
				author: $cardMeta[$row['documentId']]['author'] ?? null,
			);
		}

		return $items;
	}

	/**
	 * Archived children of each card, counted the way the level itself is read: in a base the reader
	 * holds, everything under the node; in a base they only reach by a grant, the children they were
	 * granted. Counted otherwise, the card offers an expander that opens onto nothing.
	 *
	 * @param array<int, Document> $documents by id
	 * @return array<int, int> documentId => archived children
	 */
	private function countArchivedChildren(int $userId, array $documents): array
	{
		if (empty($documents))
		{
			return [];
		}

		$collectionOf = [];
		foreach ($documents as $documentId => $document)
		{
			$collectionOf[$documentId] = (int)$document->getCollectionId();
		}

		[$heldCollections] = $this->splitByReach($userId, array_values(array_unique($collectionOf)));
		$held = array_flip($heldCollections);

		$heldIds = [];
		$heldCollectionIds = [];
		$grantedIds = [];
		$grantedCollectionIds = [];
		foreach ($collectionOf as $documentId => $collectionId)
		{
			if (isset($held[$collectionId]))
			{
				$heldIds[] = $documentId;
				$heldCollectionIds[$collectionId] = true;
			}
			else
			{
				$grantedIds[] = $documentId;
				$grantedCollectionIds[$collectionId] = true;
			}
		}

		// Each half is counted over the bases of its own cards: the count runs on the branch index,
		// whose head is the base, so naming only the bases in play keeps the range off the archive of
		// the whole portal.
		$counts = empty($heldIds)
			? []
			: $this->documentRepository->getArchivedChildCountMap(
				array_keys($heldCollectionIds),
				$heldIds,
			)
		;
		if (empty($grantedIds))
		{
			return $counts;
		}

		$codesPersonal = DocumentAccessService::personalCodes(CollectionAccessService::buildUserAccessCodes($userId));
		if (empty($codesPersonal))
		{
			return $counts;
		}

		return $counts + $this->documentRepository->getArchivedChildCountMap(
			array_keys($grantedCollectionIds),
			$grantedIds,
			DocumentAccessService::buildDocumentGrantFilter('ID', $codesPersonal),
		);
	}

	/**
	 * One page inside a search section. Cards here carry a path instead of an expander: the
	 * result mixes bases and levels, and structure is expressed by where a document comes from, not
	 * by nesting.
	 *
	 * The cursor here has a shape of its own: a ranked list has no date key to run a keyset on, so the
	 * page position is an offset and says so. Squeezing it into the {changedAt, id} of the other
	 * surfaces would put a number that is not an identifier into a field called `id`, and on the
	 * client such a cursor would be indistinguishable from a real keyset.
	 *
	 * @param array{offset: int}|null $cursor
	 * @return array{items: NodeDto[], nextCursor: array{offset: int}|null}
	 */
	public function getSearchLevel(
		int $userId,
		string $query,
		?int $collectionId,
		?array $cursor = null,
		int $limit = self::DEFAULT_LIMIT,
	): array
	{
		$limit = $this->normalizeLimit($limit);
		$boolean = $this->searchQueryBuilder->prepareBooleanQuery($query);
		if ($boolean === '')
		{
			return ['items' => [], 'nextCursor' => null];
		}

		$offset = max(0, (int)($cursor['offset'] ?? 0));
		$accessCodes = CollectionAccessService::buildUserAccessCodes($userId);
		$isAdmin = DocumentAccessService::isPortalAdmin($userId);
		$allowedCollectionIds = $this->resolveAllowedCollectionIds($userId, $isAdmin, $accessCodes);
		$hasDocumentGrants = !$isAdmin && DocumentAccessService::userHasAnyGrant($accessCodes);

		$results = $this->searchRepository->searchInSection(
			booleanQuery: $boolean,
			collectionId: $collectionId ?? 0,
			limit: $limit + 1,
			offset: $offset,
			allowedCollectionIds: $allowedCollectionIds,
			snippetTokens: $this->searchQueryBuilder->extractTokens($query),
			userAccessCodes: $accessCodes,
			hasDocumentGrants: $hasDocumentGrants,
			titleBoostTokens: $this->searchQueryBuilder->extractTokens($query),
		);

		/** @var SearchResult[] $rows */
		$rows = iterator_to_array($results);
		$nextCursor = null;
		if (count($rows) > $limit)
		{
			$rows = array_slice($rows, 0, $limit);
			$nextCursor = ['offset' => $offset + $limit];
		}

		return [
			'items' => $this->buildSearchItems($rows, $allowedCollectionIds, $isAdmin, $accessCodes),
			'nextCursor' => $nextCursor,
		];
	}

	/**
	 * First page of several search sections at once. The ranking is read once and split by knowledge
	 * base afterwards, which is exactly how the sections themselves were counted - and the order
	 * inside a base is the same subsequence of the same ranking, so a page taken from here and the
	 * next one asked for by offset meet without a gap or a repeat.
	 *
	 * @param int[] $collectionIds
	 * @return array<int, array{items: NodeDto[], nextCursor: array{offset: int}|null}>
	 */
	public function getSearchTopLevels(
		int $userId,
		string $query,
		array $collectionIds,
		int $limit = self::DEFAULT_LIMIT,
	): array
	{
		$limit = $this->normalizeLimit($limit);
		$boolean = $this->searchQueryBuilder->prepareBooleanQuery($query);
		$ids = array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $collectionIds),
			static fn(int $id): bool => $id > 0,
		)));
		if ($boolean === '' || empty($ids))
		{
			return [];
		}

		$accessCodes = CollectionAccessService::buildUserAccessCodes($userId);
		$isAdmin = DocumentAccessService::isPortalAdmin($userId);
		$allowedCollectionIds = $this->resolveAllowedCollectionIds($userId, $isAdmin, $accessCodes);
		$hasDocumentGrants = !$isAdmin && DocumentAccessService::userHasAnyGrant($accessCodes);

		$window = count($ids) * ($limit + 1);
		// The window is read without the texts: it is wider than what is kept - a base may hold none
		// of the top of the ranking - so the snippets are read afterwards, for the rows that survived
		// the split (see DocumentSearchRepository::fetchSnippets()).
		//
		// And it is read over the bases asked about rather than over everything the reader may see:
		// the size of the window is counted from their number, so spending it on the ranking of other
		// bases leaves the asked-for ones out of an answer that was supposed to be about them.
		$results = $this->searchRepository->searchAcrossSections(
			booleanQuery: $boolean,
			limit: $window,
			allowedCollectionIds: $allowedCollectionIds,
			snippetTokens: [],
			userAccessCodes: $accessCodes,
			hasDocumentGrants: $hasDocumentGrants,
			titleBoostTokens: $this->searchQueryBuilder->extractTokens($query),
			sectionCollectionIds: $ids,
		);

		/** @var SearchResult[] $rows */
		$rows = iterator_to_array($results);
		$exhausted = count($rows) < $window;

		$wanted = array_fill_keys($ids, true);
		$grouped = [];
		foreach ($rows as $row)
		{
			$collectionId = $row->getCollectionId();
			if (isset($wanted[$collectionId]))
			{
				$grouped[$collectionId][] = $row;
			}
		}

		$pages = [];
		$batch = [];
		foreach ($grouped as $collectionId => $sectionRows)
		{
			$overflows = count($sectionRows) > $limit;
			if (!$overflows && !$exhausted)
			{
				continue;
			}

			$sectionRows = array_slice($sectionRows, 0, $limit);
			$pages[$collectionId] = [
				'rows' => $sectionRows,
				'nextCursor' => $overflows ? ['offset' => $limit] : null,
			];
			foreach ($sectionRows as $row)
			{
				$batch[] = $row;
			}
		}

		if ($exhausted)
		{
			foreach ($ids as $collectionId)
			{
				$pages[$collectionId] ??= ['rows' => [], 'nextCursor' => null];
			}
		}

		if (empty($pages))
		{
			return [];
		}

		$snippets = $this->searchRepository->fetchSnippets(
			array_map(static fn(SearchResult $row): int => $row->getDocumentId(), $batch),
			$this->searchQueryBuilder->extractTokens($query),
		);
		$items = $this->buildSearchItems($batch, $allowedCollectionIds, $isAdmin, $accessCodes, $snippets);
		$byDocumentId = [];
		foreach ($items as $item)
		{
			$byDocumentId[$item->documentId] = $item;
		}

		$out = [];
		foreach ($pages as $collectionId => $page)
		{
			$sectionItems = [];
			foreach ($page['rows'] as $row)
			{
				$item = $byDocumentId[$row->getDocumentId()] ?? null;
				if ($item !== null)
				{
					$sectionItems[] = $item;
				}
			}

			$out[$collectionId] = ['items' => $sectionItems, 'nextCursor' => $page['nextCursor']];
		}

		return $out;
	}

	/**
	 * @param SearchResult[] $rows
	 * @param int[]|null $allowedCollectionIds null is the whole portal - the path builder is told so by
	 *        `$isAdmin` and needs no list to check against
	 * @return NodeDto[] in the order of the rows
	 */
	private function buildSearchItems(
		array $rows,
		?array $allowedCollectionIds,
		bool $isAdmin,
		array $accessCodes = [],
		array $snippets = [],
	): array
	{
		if (empty($rows))
		{
			return [];
		}

		$documentIds = array_map(static fn(SearchResult $row): int => $row->getDocumentId(), $rows);
		$documents = $this->indexById($this->documentRepository->getMetaByIds(
			$documentIds,
			['ID', 'TITLE', 'PARENT_ID', 'COLLECTION_ID', 'CREATED_BY', 'UPDATED_AT'],
		));
		$paths = $this->buildPaths($documentIds, $documents, $allowedCollectionIds, $isAdmin, $accessCodes);
		// The snippet comes from the index, so the card meta resolver - which exists to build excerpts
		// out of markdown - would only be paying for work the search has already done.
		$authors = $this->resolveAuthors($documents);

		$items = [];
		foreach ($rows as $row)
		{
			$documentId = $row->getDocumentId();
			$document = $documents[$documentId] ?? null;
			if ($document === null)
			{
				continue;
			}

			$updatedAt = $document->getUpdatedAt();
			$items[] = new NodeDto(
				documentId: $documentId,
				title: (string)$document->getTitle(),
				// The highlighted snippet is what the search shows instead of the plain excerpt, and it
				// travels in a field of its own: a card prints an excerpt as text, so a snippet sent as
				// one puts the `mark` tags on screen instead of highlighting the hit inside it.
				excerpt: '',
				authorId: (int)$document->getCreatedBy(),
				changedAt: $updatedAt !== null ? $updatedAt->format('c') : '',
				state: ContentState::Live,
				childCount: 0,
				hasChildren: false,
				path: $paths[$documentId] ?? [],
				author: $authors[$documentId] ?? null,
				snippet: $snippets[$documentId] ?? $row->getSnippet(),
			);
		}

		return $items;
	}

	/**
	 * One level of the live tree, in the same card shape. The order is POSITION, not a date:
	 * in a live tree the order is the user's, set by dragging, and re-sorting it by date would take
	 * that away. The bin and the archive have no manual order to take away.
	 *
	 * @param array{position: int, id: int}|null $cursor
	 * @return array{items: NodeDto[], nextCursor: array{position: int, id: int}|null}
	 */
	public function getLiveLevel(
		int $userId,
		int $collectionId,
		?int $parentId = null,
		?array $cursor = null,
		int $limit = self::DEFAULT_LIMIT,
	): array
	{
		$limit = $this->normalizeLimit($limit);

		$page = (new TreeProvider())->getListByParent(
			$collectionId,
			$parentId,
			$limit,
			isset($cursor['position']) ? (int)$cursor['position'] : null,
			isset($cursor['id']) ? (int)$cursor['id'] : null,
		);

		$documentIds = array_map(static fn(array $row): int => (int)$row['id'], $page['documents']);
		$documents = $this->indexById($this->documentRepository->getMetaByIds(
			$documentIds,
			['ID', 'TITLE', 'PARENT_ID', 'COLLECTION_ID', 'CREATED_BY', 'UPDATED_AT'],
		));
		// Same mode the level itself was read in: inside a deleted base the level lists everything,
		// so the counters on its cards have to count the same thing or the sole of a card would
		// promise nothing while the card opens a branch.
		$childCounts = $this->documentRepository->getChildrenCountMapByParentIds(
			$collectionId,
			$documentIds,
			!DocumentAccessService::isCollectionAlive($collectionId),
		);
		// The tree reader brings its own excerpt, so only the author is missing here.
		$authors = $this->resolveAuthors($documents);

		$items = [];
		foreach ($page['documents'] as $row)
		{
			$documentId = (int)$row['id'];
			$document = $documents[$documentId] ?? null;
			if ($document === null)
			{
				continue;
			}

			$updatedAt = $document->getUpdatedAt();
			$items[] = new NodeDto(
				documentId: $documentId,
				title: (string)$row['title'],
				excerpt: (string)($row['excerpt'] ?? ''),
				authorId: (int)$document->getCreatedBy(),
				changedAt: $updatedAt !== null ? $updatedAt->format('c') : '',
				state: ContentState::Live,
				// The live probe answers "any at all", not "how many"; a real count of live children
				// belongs to the tree's own reader and is not worth a second aggregate per page here.
				childCount: (int)($childCounts[$documentId] ?? 0),
				hasChildren: ($row['hasChildren'] ?? false) === true,
				author: $authors[$documentId] ?? null,
			);
		}

		return ['items' => $items, 'nextCursor' => $page['nextCursor']];
	}

	/**
	 * @param int[] $documentIds
	 * @param array<int, Document> $documents
	 * @param int[]|null $allowedCollectionIds
	 * @param string[] $accessCodes
	 * @return array<int, PathSegmentDto[]>
	 */
	private function buildPaths(
		array $documentIds,
		array $documents,
		?array $allowedCollectionIds,
		bool $isAdmin,
		array $accessCodes = [],
	): array
	{
		return (new PathResolver($this->documentRepository, $this->collectionRepository))
			->resolve($documentIds, $documents, $allowedCollectionIds ?? [], $isAdmin, $accessCodes);
	}

	/**
	 * @param int[] $accessCodes
	 * @return int[]
	 */
	/**
	 * @return int[]|null null for a portal administrator: their reach is the whole portal, and the
	 *         list of every base standing in for it used to be cut at the bound of the section list -
	 *         so on a portal with more bases than that, the search simply did not look inside the ones
	 *         past the cut.
	 */
	private function resolveAllowedCollectionIds(int $userId, bool $isAdmin, array $accessCodes): ?array
	{
		if ($isAdmin)
		{
			return null;
		}

		return DocumentAccessService::accessibleCollectionIds(
			CollectionAccessService::getAllUserLevels($accessCodes)['effective'] ?? [],
		);
	}

	/**
	 * The archive is about live bases. A base in the recycle bin went there with its whole tree, so
	 * its archived documents are in the bin too and belong to the bin's own rights - read as archive
	 * they would answer to VIEW and show the content of a deleted base to a plain reader. The rights
	 * on the base are asked about elsewhere; this is only about the state of the base.
	 */
	private function archiveReadable(int $collectionId): bool
	{
		return $collectionId <= 0 || DocumentAccessService::collectionStateAllowed(
			$collectionId,
			[DocumentAccessService::STATE_ARCHIVED],
		);
	}

	/**
	 * The same gate for a batch of bases: one read instead of one per base.
	 *
	 * @param int[] $collectionIds
	 * @return int[]
	 */
	private function dropTrashedCollections(array $collectionIds): array
	{
		$ids = array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $collectionIds),
			static fn(int $id): bool => $id > 0,
		)));
		if (empty($ids))
		{
			return [];
		}

		$trashed = array_flip($this->collectionRepository->filterTrashedIds($ids));

		return array_values(array_filter($ids, static fn(int $id): bool => !isset($trashed[$id])));
	}

	/**
	 * Does the reader hold the knowledge base itself, or did they get in by a grant on something
	 * inside it? The two are read by different queries, and a portal administrator holds everything.
	 */
	private function holdsCollection(int $userId, int $collectionId): bool
	{
		return CollectionAccessService::userHasLevel(
			$collectionId,
			$userId,
			CollectionAccessService::LEVEL_VIEW,
		);
	}

	/**
	 * @param int[] $collectionIds
	 * @return array{0: int[], 1: int[]} bases the reader holds, and bases they only reach by a grant
	 */
	private function splitByReach(int $userId, array $collectionIds): array
	{
		$ids = array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $collectionIds),
			static fn(int $id): bool => $id > 0,
		)));
		if (empty($ids) || DocumentAccessService::isPortalAdmin($userId))
		{
			return [$ids, []];
		}

		$accessCodes = CollectionAccessService::buildUserAccessCodes($userId);
		$levels = CollectionAccessService::getAllUserLevels($accessCodes)['effective'] ?? [];

		$held = [];
		$granted = [];
		foreach ($ids as $collectionId)
		{
			if ((int)($levels[$collectionId] ?? 0) >= CollectionAccessService::LEVEL_VIEW)
			{
				$held[] = $collectionId;
			}
			else
			{
				$granted[] = $collectionId;
			}
		}

		return [$held, $granted];
	}

	/**
	 * @param Document[] $documents
	 * @return array<int, Document>
	 */
	private function indexById(array $documents): array
	{
		$out = [];
		foreach ($documents as $document)
		{
			$out[(int)$document->getId()] = $document;
		}

		return $out;
	}

	/**
	 * Bases the cards of a page came from. The child counters are asked by base as well as by
	 * parent - that is the head of every branch index of the document table - and one page may hold
	 * cards of several bases.
	 *
	 * @param array<int, Document> $documents
	 * @return int[]
	 */
	private function collectionIdsOf(array $documents): array
	{
		$ids = [];
		foreach ($documents as $document)
		{
			$collectionId = (int)$document->getCollectionId();
			if ($collectionId > 0)
			{
				$ids[$collectionId] = true;
			}
		}

		return array_keys($ids);
	}

	/**
	 * Authors of a page, in the shape the flat listings answered with, resolved in one batch. Used
	 * where the card meta resolver would be too much: it reads markdown to build excerpts, and the
	 * search and the live tree already have theirs.
	 *
	 * @param array<int, Document> $documents
	 * @return array<int, array{id: int, name: string, photoUrl: ?string, color: string, isSystem?: true}>
	 */
	private function resolveAuthors(array $documents): array
	{
		$authorIdByDocumentId = [];
		foreach ($documents as $documentId => $document)
		{
			$authorIdByDocumentId[$documentId] = (int)$document->getCreatedBy();
		}

		$authors = $this->authorResolver->resolve(array_values($authorIdByDocumentId));

		$out = [];
		foreach ($authorIdByDocumentId as $documentId => $authorId)
		{
			$author = $authors[$authorId] ?? null;
			if ($author !== null)
			{
				$out[$documentId] = $author;
			}
		}

		return $out;
	}

	private function resolveCardMeta(array $documents): array
	{
		$authorIdByDocumentId = [];
		foreach ($documents as $documentId => $document)
		{
			$authorIdByDocumentId[$documentId] = (int)$document->getCreatedBy();
		}

		return $this->cardMetaResolver->resolve($authorIdByDocumentId);
	}

	/**
	 * A page per section, each read on its own by the index of its own knowledge base.
	 *
	 * The counterpart of {@see readGroupedPages()} for the sources that live on the document table.
	 * One pass over all the sections at once looks cheaper and is not: the order of these sections
	 * runs on a composite index whose head is the base, so naming several bases at once leaves the
	 * order with nothing to run on. The database then reads every row of every named base, sorts the
	 * lot and takes the window off the top - the limit stops nothing. Named one at a time, the same
	 * index gives both the range and the order, and the limit stops the read after a page.
	 *
	 * The bound is the caller's, and it is a bound on reads rather than on rows: the head of the
	 * section list shipped with it ({@see SectionLevelPreloader::PRELOAD_SECTIONS}) and, afterwards,
	 * the batch of sections scrolled to ({@see SectionLevelPreloader::MAX_BATCH_COLLECTIONS}). Each
	 * read is a page by its own index rather than a window that has to be guessed at afterwards, so
	 * a batch of many bases is many short reads in one answer - and not many answers, which is what
	 * asking per section costs in round trips.
	 *
	 * @param int[] $collectionIds
	 * @param callable(int, int): array<int, array{id: int, documentId: int, changedAt: string}> $read
	 * @return array<int, array{rows: array<int, array{id: int, documentId: int, changedAt: string}>, nextCursor: array{changedAt: string, id: int}|null}>
	 */
	private function readPagesPerCollection(array $collectionIds, int $limit, callable $read): array
	{
		$ids = array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $collectionIds),
			static fn(int $id): bool => $id > 0,
		)));

		$pages = [];
		foreach ($ids as $collectionId)
		{
			// Every asked-for section answers, empty ones included: read apart, a page is complete by
			// construction, so there is no window that ran out and nothing to guess about.
			[$rows, $nextCursor] = $this->splitPage($read($collectionId, $limit + 1), $limit);
			$pages[$collectionId] = ['rows' => $rows, 'nextCursor' => $nextCursor];
		}

		return $pages;
	}

	/**
	 * One bounded pass over the rows of several sections, cut into pages per section.
	 *
	 * The window is bounded overall rather than per section, so a portal with many bases costs one
	 * read and not one per base. A section the window did not finish is dropped from the answer: the
	 * rows it has may be a whole page or an accidental part of one, and there is no telling which.
	 *
	 * This stays the way the recycle bin is read, and only it: the bin has its own table, its order
	 * runs on the index of that table rather than on a per-base one, and its size is held down by
	 * the retention period. Both halves of the trade hold there and neither holds for the archive.
	 *
	 * @param int[] $collectionIds
	 * @param callable(int[], int): array<int, array<int, array{id: int, documentId: int, changedAt: string}>> $read
	 * @return array<int, array{rows: array<int, array{id: int, documentId: int, changedAt: string}>, nextCursor: array{changedAt: string, id: int}|null}>
	 */
	private function readGroupedPages(array $collectionIds, int $limit, callable $read): array
	{
		$ids = array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $collectionIds),
			static fn(int $id): bool => $id > 0,
		)));
		if (empty($ids))
		{
			return [];
		}

		$window = count($ids) * ($limit + 1);
		$grouped = $read($ids, $window);

		$fetched = 0;
		foreach ($grouped as $rows)
		{
			$fetched += count($rows);
		}
		$exhausted = $fetched < $window;

		$pages = [];
		foreach ($grouped as $collectionId => $rows)
		{
			[$page, $nextCursor] = $this->splitPage($rows, $limit);
			if ($nextCursor === null && !$exhausted)
			{
				continue;
			}

			$pages[(int)$collectionId] = ['rows' => $page, 'nextCursor' => $nextCursor];
		}

		if ($exhausted)
		{
			// The window ended on its own, so a section without rows has none - and saying so is what
			// keeps an empty section from asking a question the answer already holds.
			foreach ($ids as $collectionId)
			{
				$pages[$collectionId] ??= ['rows' => [], 'nextCursor' => null];
			}
		}

		return $pages;
	}

	/**
	 * @param array<int, array{rows: array<int, array{id: int, documentId: int, changedAt: string}>, nextCursor: mixed}> $pages
	 * @return array<int, array{id: int, documentId: int, changedAt: string}>
	 */
	private function mergeRows(array $pages): array
	{
		$rows = [];
		foreach ($pages as $page)
		{
			foreach ($page['rows'] as $row)
			{
				$rows[] = $row;
			}
		}

		return $rows;
	}

	/**
	 * Puts the cards built in one batch back into the sections they came from, by document.
	 *
	 * @param array<int, array{rows: array<int, array{id: int, documentId: int, changedAt: string}>, nextCursor: array{changedAt: string, id: int}|null}> $pages
	 * @param NodeDto[] $items
	 * @return array<int, array{items: NodeDto[], nextCursor: array{changedAt: string, id: int}|null}>
	 */
	private function assemblePages(array $pages, array $items): array
	{
		$byDocumentId = [];
		foreach ($items as $item)
		{
			$byDocumentId[$item->documentId] = $item;
		}

		$out = [];
		foreach ($pages as $collectionId => $page)
		{
			$sectionItems = [];
			foreach ($page['rows'] as $row)
			{
				$item = $byDocumentId[$row['documentId']] ?? null;
				if ($item !== null)
				{
					$sectionItems[] = $item;
				}
			}

			$out[$collectionId] = ['items' => $sectionItems, 'nextCursor' => $page['nextCursor']];
		}

		return $out;
	}

	/**
	 * Cuts the extra row the page was fetched with and turns it into the next cursor. The extra row
	 * IS the next page's first row, which is why the cursor is inclusive.
	 *
	 * @param array<int, array{id: int, documentId: int, changedAt: string}> $rows
	 * @return array{0: array<int, array{id: int, documentId: int, changedAt: string}>, 1: array{changedAt: string, id: int}|null}
	 */
	private function splitPage(array $rows, int $limit): array
	{
		if (count($rows) <= $limit)
		{
			return [$rows, null];
		}

		$overflow = $rows[$limit];

		return [
			array_slice($rows, 0, $limit),
			['changedAt' => $overflow['changedAt'], 'id' => $overflow['id']],
		];
	}

	private function normalizeLimit(int $limit): int
	{
		return $limit > 0 ? min($limit, self::MAX_LIMIT) : self::DEFAULT_LIMIT;
	}
}
