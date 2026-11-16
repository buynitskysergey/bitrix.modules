<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Provider\ContentList;

use Bitrix\Main\Type\DateTime;
use Bitrix\Note\Internal\Access\Service\CollectionAccessService;
use Bitrix\Note\Internal\Access\Service\DocumentAccessService;
use Bitrix\Note\Internal\Repository\CollectionRepository;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Repository\DocumentSearchRepository;
use Bitrix\Note\Internal\Repository\RecycleBinRepository;
use Bitrix\Note\Internal\Service\Search\SearchQueryBuilder;
use Bitrix\Note\Internal\Service\User\AuthorResolver;
use Bitrix\Note\Internal\Util\CursorDate;
use Bitrix\Note\Public\Dto\ContentList\ContentState;
use Bitrix\Note\Public\Dto\ContentList\SectionDto;

/**
 * First phase of every content-area list: which knowledge bases hold anything, and how
 * much. The content of a section arrives separately, by cursor - that is what stops the counter
 * from meaning "how much has loaded so far", keeps the order of the sections computed where the
 * list is, and keeps collapsing a section from starting a cascade of loads.
 *
 * One shape, three sources: the bin reads its own rows, the archive the state of the document, and
 * the search the full-text index. Sections are built only over knowledge bases the viewer can see:
 * counting everything and filtering afterwards would both leak and cost.
 *
 * Every counter is one grouped query over its source, never a query per base. A capped count per
 * base looks cheaper only one base at a time: a base holding less than the display ceiling never
 * lets its query stop early, so fifty sections walked the same rows fifty times. The cost of the
 * grouped pass is set by the source, not by how many bases the viewer can see.
 */
final class SectionProvider
{
	/**
	 * How many knowledge bases one answer covers. Not a cut: the answer carries the key of the base
	 * it stopped at, and the next page is asked for from there, exactly like the content inside a
	 * section. A bound with no way past it made the bases beyond it unreachable - the reader was told
	 * to deal with the visible ones and hope the rest turned up.
	 */
	public const MAX_SECTIONS = 50;

	/** Window of the ranking the search reads to learn which bases it hits at all. */
	private const SEARCH_DISCOVERY_WINDOW = 1000;

	public function __construct(
		private readonly RecycleBinRepository $recycleBinRepository = new RecycleBinRepository(),
		private readonly DocumentRepository $documentRepository = new DocumentRepository(),
		private readonly CollectionRepository $collectionRepository = new CollectionRepository(),
		private readonly DocumentSearchRepository $searchRepository = new DocumentSearchRepository(),
		private readonly SearchQueryBuilder $searchQueryBuilder = new SearchQueryBuilder(),
		private readonly AuthorResolver $authorResolver = new AuthorResolver(),
	) {}

	/**
	 * Sections of the recycle bin, freshest operation first: a person comes here to sort out what
	 * happened yesterday, and the alphabet does not help with that.
	 *
	 * @param array{freshness: string, collectionId: int}|null $cursor base the previous page stopped at
	 * @return array{sections: SectionDto[], hasMoreSections: bool, nextSectionCursor: ?array}
	 */
	public function getRecycleBinSections(int $userId, ?array $cursor = null, int $limit = self::MAX_SECTIONS): array
	{
		if ($userId <= 0)
		{
			return self::emptyPage();
		}

		$limit = self::normalizeLimit($limit);

		// No early exit on "this reader sees no base at all": an orphan belongs to no base by
		// definition and is answered by authorship instead, so a person who deleted their last base
		// still has to find its documents here. The tally below answers nothing for an empty list on
		// its own, which is all the shortcut was worth.
		$visibility = $this->resolveRecycleBinVisibility($userId);

		// The page is taken from what the bin actually holds, freshest first, and not from a prefix of
		// the portal's bases. Ordered by base rather than by content, the list hid exactly what a
		// person comes here for - yesterday's deletion - behind bases with nothing deleted in them.
		$probe = $limit + 1;
		$binTally = $this->recycleBinRepository->tallyRootsByFreshness(
			$visibility['collectionIds'],
			$probe,
			$cursor,
		);

		// The second source of sections, and the only one for a base whose deletion left the bin no
		// top-level row: the description of a base is never the top of a section, so a base holding no
		// ordinary documents - every base just created - had no section and no way back (AC-003, AC-005).
		// The same is reached from the other side, by destroying the last card inside a deleted base.
		$trashedFreshness = $this->collectionRepository->listTrashedByFreshness(
			$visibility['collectionIds'],
			$probe,
			$cursor,
		);

		$candidateIds = array_values(array_unique(array_merge(
			array_map('intval', array_keys($binTally)),
			array_map('intval', array_keys($trashedFreshness)),
		)));
		$states = $this->collectionRepository->getStatesByIds($candidateIds);
		$trashers = $this->resolveTrashers($states);

		$freshnessById = [];
		foreach ($candidateIds as $collectionId)
		{
			$state = $states[$collectionId] ?? null;
			// The base is gone from the table - an old deletion, or one the TTL has already swept - and
			// its documents are orphans, which is what the technical section below is for.
			if ($state === null)
			{
				continue;
			}

			// One key per base wherever the base came from, and the deletion of the base is the later
			// of the two by construction: the documents reach the bin before the base is marked. Read
			// from the state and not from the source it turned up in, so that a base found by only one
			// of the two sources on this page is ordered exactly as it was on the previous one.
			$freshnessById[$collectionId] = ($state['isTrashed'] ?? false) === true
				? self::sortableMoment($state['trashedAt'] ?? null)
				: (string)($binTally[$collectionId]['latestAt'] ?? '')
			;
		}

		$freshnessById = self::dropBeyondCursor($freshnessById, $cursor);

		$order = array_keys($freshnessById);
		usort($order, static function (int $a, int $b) use ($freshnessById): int {
			return strcmp($freshnessById[$b], $freshnessById[$a]) ?: ($b <=> $a);
		});

		$hasMore = count($order) > $limit;
		$collectionIds = array_slice($order, 0, $limit);
		$nextCursor = null;
		if ($hasMore && !empty($collectionIds))
		{
			$lastId = (int)$collectionIds[count($collectionIds) - 1];
			$nextCursor = ['freshness' => $freshnessById[$lastId], 'collectionId' => $lastId];
		}

		$result = [];
		foreach ($collectionIds as $collectionId)
		{
			$state = $states[$collectionId];
			$count = (int)($binTally[$collectionId]['count'] ?? 0);

			// An empty section only for a base that is itself in the bin: it stands for the base, which
			// is what the reader restores, and it says so with a counter of zero. For a live base the
			// section is its deleted documents and nothing else, so with none of them there is nothing
			// to show.
			if ($count === 0 && ($state['isTrashed'] ?? false) !== true)
			{
				continue;
			}

			$result[] = $this->buildCollectionSection($collectionId, $state, $count, $trashers);
		}

		// One section by definition, and it belongs to the first page: appended to every page it would
		// come back over and over as the reader scrolls.
		if ($cursor === null)
		{
			$orphanCount = $this->recycleBinRepository->countOrphansCapped(
				$visibility['isAdmin'] ? null : $userId,
				SectionDto::COUNT_LIMIT,
			);
			if ($orphanCount > 0)
			{
				$result[] = SectionDto::withoutCollection($orphanCount);
			}
		}

		return self::page($result, $nextCursor);
	}

	/**
	 * Sections of the archive, alphabetically: freshness is not a task here.
	 *
	 * @param array{title: string, collectionId: int}|null $cursor base the previous page stopped at
	 * @return array{sections: SectionDto[], hasMoreSections: bool, nextSectionCursor: ?array}
	 */
	public function getArchiveSections(int $userId, ?array $cursor = null, int $limit = self::MAX_SECTIONS): array
	{
		if ($userId <= 0)
		{
			return self::emptyPage();
		}

		$limit = self::normalizeLimit($limit);
		$visibility = $this->resolveVisibility($userId);

		// Both reaches at once: the bases the reader holds, and the bases they were let into a corner
		// of. Archiving a document does not take away the access somebody was given to it, and the
		// flat archive this list replaced showed such documents - built over held bases alone, the new
		// one lost them. Such a section is named by its base and holds the part of the subtree the
		// reader may see, exactly as the sidebar draws content somebody shared with them.
		$codesPersonal = $visibility['isAdmin']
			? []
			: DocumentAccessService::personalCodes(CollectionAccessService::buildUserAccessCodes($userId))
		;
		if (
			$visibility['collectionIds'] !== null
			&& empty($visibility['collectionIds'])
			&& empty($codesPersonal)
		)
		{
			return self::emptyPage();
		}

		// The order is the name of the base, and so is the key of the page: the list is read
		// alphabetically, so it has to be paged alphabetically too.
		$tally = $this->documentRepository->tallyArchivedRootsByCollection(
			$visibility['collectionIds'],
			$limit + 1,
			$cursor,
			$codesPersonal,
		);
		if (empty($tally))
		{
			return self::emptyPage();
		}

		$hasMore = count($tally) > $limit;
		$page = array_slice($tally, 0, $limit, true);
		$states = $this->collectionRepository->getStatesByIds(array_keys($page));
		$trashers = $this->resolveTrashers($states);

		$sections = [];
		$lastKey = null;
		foreach ($page as $collectionId => $entry)
		{
			$lastKey = ['title' => $entry['title'], 'collectionId' => (int)$collectionId];
			$state = $states[$collectionId] ?? null;
			if ($entry['count'] === 0 || $state === null)
			{
				continue;
			}

			$sections[] = $this->buildCollectionSection($collectionId, $state, $entry['count'], $trashers);
		}

		return self::page($sections, $hasMore ? $lastKey : null);
	}

	/**
	 * Sections of the search. Grouping happens here rather than over whatever landed on a
	 * page: sliced off a flat ranking, one knowledge base would show up on the first page and again
	 * on the third.
	 *
	 * @param array{offset: int}|null $cursor place in the ranking the previous page stopped at
	 * @return array{sections: SectionDto[], hasMoreSections: bool, nextSectionCursor: ?array}
	 */
	public function getSearchSections(
		int $userId,
		string $query,
		?array $cursor = null,
		int $limit = self::MAX_SECTIONS,
	): array
	{
		if ($userId <= 0)
		{
			return self::emptyPage();
		}

		$limit = self::normalizeLimit($limit);
		$boolean = $this->searchQueryBuilder->prepareBooleanQuery($query);
		if ($boolean === '')
		{
			return self::emptyPage();
		}

		// Rights only, no bound: which bases the query hits is decided by the query, and the list of
		// sections is cut afterwards. Bounded up front - the fifty oldest bases the viewer may see -
		// the search looked inside those fifty and nowhere else, so on a portal with more of them a
		// hit in a newer base was reported as no hit at all. Both phases of the search read the same
		// reach, so a section offered here is one LevelProvider will look inside.
		$visibility = $this->resolveVisibility($userId);
		$accessCodes = CollectionAccessService::buildUserAccessCodes($userId);
		$hasDocumentGrants = !$visibility['isAdmin'] && DocumentAccessService::userHasAnyGrant($accessCodes);

		$tally = $this->searchRepository->tallyHitsByCollection(
			$boolean,
			$visibility['collectionIds'],
			self::SEARCH_DISCOVERY_WINDOW,
			$accessCodes,
			$hasDocumentGrants,
			$this->searchQueryBuilder->extractTokens($query),
		);
		if (empty($tally))
		{
			return self::emptyPage();
		}

		// The bases the query hits, strongest hit first. The page is cut out of that order, so the
		// bases that matched best are read first and the rest are asked for by their place in it -
		// the ranking is computed once, over the whole discovery window, and does not shift under a
		// reader who is paging through it.
		$hitCollectionIds = array_values(array_filter(
			array_keys($tally),
			static fn(int $collectionId): bool => $collectionId > 0,
		));

		$offset = max(0, (int)($cursor['offset'] ?? 0));
		$pageIds = array_slice($hitCollectionIds, $offset, $limit);
		$states = $this->collectionRepository->getStatesByIds($pageIds);
		$trashers = $this->resolveTrashers($states);

		$sections = [];
		foreach ($pageIds as $collectionId)
		{
			$rawCount = $tally[$collectionId] ?? 0;
			$state = $states[$collectionId] ?? null;
			if ($rawCount === 0 || $state === null)
			{
				continue;
			}

			$sections[] = $this->buildCollectionSection($collectionId, $state, $rawCount, $trashers);
		}

		// The order of the sections is the order the page was cut in, and not the alphabet: sorted
		// alphabetically inside the page, the list restarted the alphabet on every page, so a base
		// named Alpha came after Zulu for no reason a reader could see. The archive is alphabetical
		// and is paged alphabetically; the search is ranked and is paged by that same ranking.

		// One section by definition, and it belongs to the first page - appended to every page it
		// would come back over and over as the reader pages through the bases.
		if ($offset === 0 && ($tally[0] ?? 0) > 0)
		{
			$sections[] = SectionDto::withoutCollection($tally[0]);
		}

		$nextOffset = $offset + count($pageIds);

		return self::page(
			$sections,
			$nextOffset < count($hitCollectionIds) ? ['offset' => $nextOffset] : null,
		);
	}

	/**
	 * Rights, and only rights: which knowledge bases the viewer may see at all. A null id list means
	 * no restriction - a portal administrator sees the whole portal, and standing in for that with a
	 * list of ids is what turned the bound of the answer into a bound on which bases are considered.
	 * The surface applies the bound itself, over its own content.
	 *
	 * A base lying in the recycle bin is not in this answer: the archive and the search are about
	 * live bases, and a deleted one is shown by the bin alone - see resolveRecycleBinVisibility().
	 *
	 * @return array{collectionIds: int[]|null, isAdmin: bool}
	 */
	private function resolveVisibility(int $userId): array
	{
		if (DocumentAccessService::isPortalAdmin($userId))
		{
			return ['collectionIds' => null, 'isAdmin' => true];
		}

		return [
			'collectionIds' => DocumentAccessService::accessibleCollectionIds($this->userLevels($userId)),
			'isAdmin' => false,
		];
	}

	/**
	 * The same question for the recycle bin, answered by another rule. The level map is taken raw,
	 * because a deleted base drops out of accessibleCollectionIds() exactly while the bin still has
	 * to show it, and a base found there answers at MODERATE rather than VIEW: its card stands for
	 * the base as a whole, not for one document inside it (AC-012, AC-013).
	 *
	 * A live base keeps answering at VIEW - the documents somebody deleted out of it are shown to
	 * its readers as they always were, and raising that threshold too would empty the bin of them.
	 *
	 * @return array{collectionIds: int[]|null, isAdmin: bool}
	 */
	private function resolveRecycleBinVisibility(int $userId): array
	{
		if (DocumentAccessService::isPortalAdmin($userId))
		{
			return ['collectionIds' => null, 'isAdmin' => true];
		}

		$readable = [];
		foreach ($this->userLevels($userId) as $collectionId => $level)
		{
			if ((int)$level >= CollectionAccessService::LEVEL_VIEW)
			{
				$readable[(int)$collectionId] = (int)$level;
			}
		}

		foreach ($this->collectionRepository->filterTrashedIds(array_keys($readable)) as $collectionId)
		{
			if ($readable[$collectionId] < CollectionAccessService::LEVEL_MODERATE)
			{
				unset($readable[$collectionId]);
			}
		}

		return ['collectionIds' => array_keys($readable), 'isAdmin' => false];
	}

	/**
	 * @return array<int|string, int|string> collectionId => effective level, uncut
	 */
	private function userLevels(int $userId): array
	{
		return CollectionAccessService::getAllUserLevels(
			CollectionAccessService::buildUserAccessCodes($userId),
		)['effective'] ?? [];
	}

	/**
	 * @return array{sections: SectionDto[], hasMoreSections: bool, nextSectionCursor: ?array}
	 */
	private static function emptyPage(): array
	{
		return self::page([], null);
	}

	/**
	 * One page of sections. The key of the base the page stopped at travels with it: the surface hands
	 * it back and gets the bases that follow, the same way the content inside a section is read.
	 *
	 * @param SectionDto[] $sections
	 * @return array{sections: SectionDto[], hasMoreSections: bool, nextSectionCursor: ?array}
	 */
	private static function page(array $sections, ?array $nextCursor): array
	{
		return [
			'sections' => $sections,
			'hasMoreSections' => $nextCursor !== null,
			'nextSectionCursor' => $nextCursor,
		];
	}

	/**
	 * The ordering key of a section, in the shape the keyset of both sources compares against and the
	 * shape that travels in the cursor. Compared as text, which is why the shape has to be the one
	 * with the fixed-width parts.
	 */
	private static function sortableMoment(mixed $value): string
	{
		return $value instanceof DateTime ? $value->format('Y-m-d H:i:s') : (string)($value ?? '');
	}

	/**
	 * The keyset of the page, applied once to the merged list. Each source cut its own page by its own
	 * column, and for a base in the bin the two columns are not the same moment: without this a base
	 * already drawn on an earlier page - by the key of its deletion - could come back on this one by
	 * the key of the documents inside it.
	 *
	 * @param array<int, string> $freshnessById
	 * @param array{freshness: string, collectionId: int}|null $cursor
	 * @return array<int, string>
	 */
	private static function dropBeyondCursor(array $freshnessById, ?array $cursor): array
	{
		$at = self::sortableMoment(CursorDate::parse($cursor['freshness'] ?? null));
		$afterId = isset($cursor['collectionId']) ? (int)$cursor['collectionId'] : 0;
		if ($at === '' || $afterId <= 0)
		{
			return $freshnessById;
		}

		return array_filter(
			$freshnessById,
			static function (string $freshness, int $collectionId) use ($at, $afterId): bool {
				$compared = strcmp($freshness, $at);

				return $compared < 0 || ($compared === 0 && $collectionId < $afterId);
			},
			ARRAY_FILTER_USE_BOTH,
		);
	}

	private static function normalizeLimit(int $limit): int
	{
		if ($limit <= 0)
		{
			return self::MAX_SECTIONS;
		}

		return min($limit, self::MAX_SECTIONS);
	}

	/**
	 * A section stands for a knowledge base that is there to be named and opened, so the state of
	 * that base is required rather than optional: a caller holding none has an orphan on its hands,
	 * and orphans belong to the technical section instead.
	 *
	 * A base in the bin reads as deleted even when it was archived first: the archive is where it
	 * used to be, the bin is where it is.
	 *
	 * @param array{id: int, name: string, isArchived: bool, isTrashed: bool, trashedAt: mixed,
	 *        trashedBy: ?int} $state
	 * @param array<int, array{id: int, name: string, photoUrl: ?string, color: string}> $trashers
	 */
	private function buildCollectionSection(
		int $collectionId,
		array $state,
		int $rawCount,
		array $trashers = [],
	): SectionDto
	{
		$isTrashed = ($state['isTrashed'] ?? false) === true;

		return SectionDto::forCollection(
			collectionId: $collectionId,
			title: (string)($state['name'] ?? ''),
			state: match (true)
			{
				$isTrashed => ContentState::Trashed,
				($state['isArchived'] ?? false) === true => ContentState::Archived,
				default => ContentState::Live,
			},
			rawCount: $rawCount,
			trashedBy: $isTrashed ? ($trashers[(int)($state['trashedBy'] ?? 0)] ?? null) : null,
			trashedAt: $isTrashed ? self::formatMoment($state['trashedAt'] ?? null) : null,
		);
	}

	/**
	 * Names the people who sent these bases to the bin, in one read for the whole page. A section
	 * names a person the same way every other card of the module does - id, name, photo, colour -
	 * because the card drawing it is the same card.
	 *
	 * @param array<int, array{trashedBy: ?int, isTrashed: bool}> $states
	 * @return array<int, array{id: int, name: string, photoUrl: ?string, color: string}>
	 */
	private function resolveTrashers(array $states): array
	{
		$ids = [];
		foreach ($states as $state)
		{
			if (($state['isTrashed'] ?? false) === true && (int)($state['trashedBy'] ?? 0) !== 0)
			{
				$ids[(int)$state['trashedBy']] = true;
			}
		}

		return $ids === [] ? [] : $this->authorResolver->resolve(array_keys($ids));
	}

	/** The wire format the rest of the content area dates in - see NodeContextResolver. */
	private static function formatMoment(mixed $value): ?string
	{
		if ($value instanceof DateTime)
		{
			return $value->format('c');
		}

		return $value === null ? null : (string)$value;
	}
}
