<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Provider\ContentList;

use Bitrix\Note\Internal\Access\Service\CollectionAccessService;
use Bitrix\Note\Internal\Access\Service\DocumentAccessService;
use Bitrix\Note\Internal\Repository\CollectionRepository;
use Bitrix\Note\Public\Dto\ContentList\SectionDto;

/**
 * Content of the sections: the part shipped together with the section list, and the same map for
 * sections named by the caller afterwards.
 *
 * Sections come up open, so without this every open of the bin cost one request per knowledge base:
 * the list arrived, and each section then asked for its own content. That is a price growing with
 * something the reader did not choose - how many bases they happen to have access to.
 *
 * The bound below is what keeps the price of the answer fixed: only that many sections arrive with
 * their content, and past it the sections are asked for as they are scrolled to - in one batch and
 * not one request per section. Both ways build the map here and with the same code, so a client
 * reads the preloaded one and the asked-for one with the same parser.
 *
 * How each source reads its share is the source's own business - the bin in one pass over its own
 * table, the archive a page per base, because its order is indexed by the base (see
 * LevelProvider::readPagesPerCollection()).
 */
final class SectionLevelPreloader
{
	/**
	 * How many sections arrive with their content. Enough to cover any first screen - sections are
	 * headers with up to fifty cards each - while keeping the grouped window bounded.
	 */
	public const PRELOAD_SECTIONS = 10;

	/**
	 * How many sections one batch answers for. A page of sections is the most a list can hold at
	 * once, so past it the request is asking about sections nobody has on screen.
	 */
	public const MAX_BATCH_COLLECTIONS = SectionProvider::MAX_SECTIONS;

	/**
	 * The same for the search, whose batch is priced by two numbers at once: the ranking of the bases
	 * asked about is read in one window sized `count(ids) * (limit + 1)`, and the snippets of the rows
	 * that survive the split are read after it, text by text. So what the answer costs is the product
	 * of the two and not either one alone.
	 *
	 * Hence a bound on the product below, and this count is only its other half: a caller asking for
	 * long pages gets fewer sections in one batch, one asking for short ones gets the whole page of
	 * sections in a single answer instead of a request per screenful of scrolling.
	 */
	public const MAX_SEARCH_BATCH_COLLECTIONS = SectionProvider::MAX_SECTIONS;

	/**
	 * The rows of ranking one search batch may be sized at: a page of sections, each with the page of
	 * findings the surfaces ask by. Asked for more per section, a batch answers about fewer sections
	 * rather than reading a window several times that.
	 */
	public const MAX_SEARCH_BATCH_ROWS = SectionProvider::MAX_SECTIONS * LevelProvider::DEFAULT_LIMIT;

	/** Key of the technical "no knowledge base" section, which has no id to key by. */
	private const TECHNICAL_KEY = 'none';

	/**
	 * @param SectionDto[] $sections
	 * @return array<string, array{items: array, nextCursor: ?array}> keyed by collection id as string
	 */
	public static function forRecycleBin(int $userId, array $sections): array
	{
		$sections = self::head($sections);
		if (empty($sections))
		{
			return [];
		}

		$provider = new LevelProvider();

		$collectionIds = [];
		$hasOrphanSection = false;
		foreach ($sections as $section)
		{
			if ($section->collectionId === null)
			{
				$hasOrphanSection = true;
			}
			else
			{
				$collectionIds[] = $section->collectionId;
			}
		}

		$levels = $provider->getRecycleBinRootLevels($userId, $collectionIds, LevelProvider::DEFAULT_LIMIT);

		$out = self::keyByCollection($levels);
		if ($hasOrphanSection)
		{
			// One section by definition, so its own read is not a price per base.
			$out[self::TECHNICAL_KEY] = $provider->getRecycleBinLevel(
				$userId,
				null,
				null,
				null,
				LevelProvider::DEFAULT_LIMIT,
			);
		}

		return $out;
	}

	/**
	 * The same map for sections the caller names - the ones scrolled to, past those whose content
	 * travelled with the list. One request for a screenful of sections instead of one per section,
	 * and the whole batch still costs one pass over the rows of the bin.
	 *
	 * A section is in the answer only when there is something to answer with: an id naming no base,
	 * a base this reader may not see, or one purged between the two requests is absent, and the
	 * client asks for such a section the way it always has. An empty section, on the other hand, is
	 * answered as empty - that is a fact about the bin, not a missing answer.
	 *
	 * @param int[] $collectionIds
	 * @return array<string, array{items: array, nextCursor: ?array}> keyed by collection id as string
	 */
	public static function forRecycleBinCollections(
		int $userId,
		array $collectionIds,
		int $limit = LevelProvider::DEFAULT_LIMIT,
	): array
	{
		$ids = self::filterOpenableInBin($userId, self::normalizeBatchIds($collectionIds));
		if (empty($ids))
		{
			return [];
		}

		$levels = (new LevelProvider())->getRecycleBinRootLevels($userId, $ids, $limit);

		return self::keyByCollection($levels);
	}

	/**
	 * @param SectionDto[] $sections
	 * @return array<string, array{items: array, nextCursor: ?array}>
	 */
	public static function forArchive(int $userId, array $sections): array
	{
		$collectionIds = self::collectionIdsOf(self::head($sections));
		if (empty($collectionIds))
		{
			return [];
		}

		$levels = (new LevelProvider())->getArchiveRootLevels(
			$userId,
			$collectionIds,
			LevelProvider::DEFAULT_LIMIT,
		);

		return self::keyByCollection($levels);
	}

	/**
	 * The archive of several named bases, a page per base - see forRecycleBinCollections() for what
	 * a batch is and what it leaves out.
	 *
	 * The two reaches into an archive are both kept, exactly as a single level of it keeps them: a
	 * base the reader holds answers with the top of its archive, and a base they were only let into
	 * a corner of answers with the granted part of it and nothing else - the rows there are read by
	 * the grant itself.
	 *
	 * @param int[] $collectionIds
	 * @return array<string, array{items: array, nextCursor: ?array}>
	 */
	public static function forArchiveCollections(
		int $userId,
		array $collectionIds,
		int $limit = LevelProvider::DEFAULT_LIMIT,
	): array
	{
		$ids = self::normalizeBatchIds($collectionIds);
		if (empty($ids))
		{
			return [];
		}

		$held = self::filterViewable($userId, $ids);
		$allowed = $held;
		if (count($held) < count($ids) && self::readerHasAnyDocumentGrant($userId))
		{
			$allowed = $ids;
		}
		if (empty($allowed))
		{
			return [];
		}

		return self::keyByCollection((new LevelProvider())->getArchiveRootLevels($userId, $allowed, $limit));
	}

	/**
	 * @param SectionDto[] $sections
	 * @param int $limit the page the surface asking pages by. The first page arrives from here, the
	 *        rest are asked for by the same surface with the same size: preloading a bigger one would
	 *        put more on the first screen than a page holds, and the scroll would then jump from a
	 *        long first page to short ones.
	 * @return array<string, array{items: array, nextCursor: ?array}>
	 */
	public static function forSearch(
		int $userId,
		string $query,
		array $sections,
		int $limit = LevelProvider::DEFAULT_LIMIT,
	): array
	{
		$collectionIds = self::collectionIdsOf(self::head($sections));
		if (empty($collectionIds))
		{
			return [];
		}

		$levels = (new LevelProvider())->getSearchTopLevels(
			$userId,
			$query,
			$collectionIds,
			$limit,
		);

		return self::keyByCollection($levels);
	}

	/**
	 * Hits of the query in several named bases - see forRecycleBinCollections() for what a batch is
	 * and what it leaves out.
	 *
	 * The gate of a search section is the query itself: a hit reached by a grant on the document
	 * makes a section out of a base the reader holds nothing on, and that section is theirs to read.
	 * So what the query found is answered as found. What it did not find is answered as an empty
	 * section only for a base the reader may see at all - naming a foreign id learns nothing from
	 * the shape of the answer.
	 *
	 * A section of the search is not priced in rows - see MAX_SEARCH_BATCH_COLLECTIONS - so what the
	 * caller asks for a page of findings by is what this batch costs. Sections past the bound are
	 * absent from the map, the same way sections past the common bound are.
	 *
	 * @param int[] $collectionIds
	 * @return array<string, array{items: array, nextCursor: ?array}>
	 */
	public static function forSearchCollections(
		int $userId,
		string $query,
		array $collectionIds,
		int $limit = LevelProvider::DEFAULT_LIMIT,
	): array
	{
		$ids = self::normalizeBatchIds($collectionIds, self::searchBatchBound($limit));
		if (empty($ids))
		{
			return [];
		}

		$levels = (new LevelProvider())->getSearchTopLevels($userId, $query, $ids, $limit);
		$viewable = array_fill_keys(self::filterViewable($userId, array_keys($levels)), true);
		foreach ($levels as $collectionId => $level)
		{
			if (empty($level['items']) && !isset($viewable[$collectionId]))
			{
				unset($levels[$collectionId]);
			}
		}

		return self::keyByCollection($levels);
	}

	/**
	 * How many sections one search batch answers for, given the page of findings asked inside each.
	 * The page is the caller's to choose, so the count is what gives way: the two are bound by their
	 * product, and neither alone says what the answer costs.
	 */
	private static function searchBatchBound(int $limit): int
	{
		$affordable = intdiv(self::MAX_SEARCH_BATCH_ROWS, max(1, $limit));

		return max(1, min(self::MAX_SEARCH_BATCH_COLLECTIONS, $affordable));
	}

	/**
	 * @param mixed[] $collectionIds
	 * @param int $max how many of them the surface answers for
	 * @return int[] positive, without repeats and no longer than the surface allows
	 */
	private static function normalizeBatchIds(array $collectionIds, int $max = self::MAX_BATCH_COLLECTIONS): array
	{
		$ids = array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $collectionIds),
			static fn(int $id): bool => $id > 0,
		)));

		return array_slice($ids, 0, max(1, $max));
	}

	/**
	 * Which of these bases the reader may see - the question a single level asks about the one base
	 * it answers, asked once for the whole batch: a level read per base is a query per base.
	 *
	 * @param int[] $ids
	 * @return int[] in the order given
	 */
	private static function filterViewable(int $userId, array $ids): array
	{
		return self::filterByLevel($userId, $ids, CollectionAccessService::LEVEL_VIEW, []);
	}

	/**
	 * The same for the bin, where a base found in the bin itself is the one thing a batch may be
	 * asked about that no other surface shows. Opening such a section is the same decision as seeing
	 * its card, so it is taken at MODERATE, and this is the list that carries it: the base is out of
	 * accessibleCollectionIds() and would otherwise never reach the reader who moderates it.
	 * A live base keeps answering at VIEW - its bin is its readers' as it always was.
	 *
	 * @param int[] $ids
	 * @return int[] in the order given
	 */
	private static function filterOpenableInBin(int $userId, array $ids): array
	{
		if (empty($ids) || $userId <= 0 || DocumentAccessService::isPortalAdmin($userId))
		{
			return $userId > 0 ? $ids : [];
		}

		$trashed = (new CollectionRepository())->filterTrashedIds($ids);

		return array_merge(
			self::filterByLevel($userId, $ids, CollectionAccessService::LEVEL_VIEW, $trashed),
			self::filterByLevel($userId, $trashed, CollectionAccessService::LEVEL_MODERATE, []),
		);
	}

	/**
	 * @param int[] $ids
	 * @param int[] $except ids left out of the answer whatever their level
	 * @return int[] in the order given
	 */
	private static function filterByLevel(int $userId, array $ids, int $threshold, array $except): array
	{
		if (empty($ids) || $userId <= 0)
		{
			return [];
		}

		$skip = array_flip($except);
		$ids = array_values(array_filter($ids, static fn(int $id): bool => !isset($skip[$id])));
		if (empty($ids))
		{
			return [];
		}

		if (DocumentAccessService::isPortalAdmin($userId))
		{
			return $ids;
		}

		// '*' rides along so that the public policy of a base counts as a level of its own.
		$levels = CollectionAccessService::batchGetUserLevels($ids, array_values(array_unique([
			...CollectionAccessService::buildUserAccessCodes($userId),
			'*',
		])));

		return array_values(array_filter($ids, static function (int $id) use ($levels, $threshold): bool {
			return (int)($levels[$id] ?? CollectionAccessService::LEVEL_NONE) >= $threshold;
		}));
	}

	/**
	 * Was this reader let into anything at all? A grant is a reach into a base they do not hold, and
	 * whether they have one is a question about the reader and not about the base - so it is asked
	 * once per batch and not once per section.
	 */
	private static function readerHasAnyDocumentGrant(int $userId): bool
	{
		$codesPersonal = DocumentAccessService::personalCodes(
			CollectionAccessService::buildUserAccessCodes($userId),
		);

		return !empty($codesPersonal) && DocumentAccessService::userHasAnyGrant($codesPersonal);
	}

	/**
	 * @param SectionDto[] $sections
	 * @return SectionDto[]
	 */
	private static function head(array $sections): array
	{
		$out = [];
		foreach (array_slice(array_values($sections), 0, self::PRELOAD_SECTIONS) as $section)
		{
			if ($section instanceof SectionDto)
			{
				$out[] = $section;
			}
		}

		return $out;
	}

	/**
	 * @param SectionDto[] $sections
	 * @return int[]
	 */
	private static function collectionIdsOf(array $sections): array
	{
		$ids = [];
		foreach ($sections as $section)
		{
			if ($section->collectionId !== null && $section->collectionId > 0)
			{
				$ids[] = $section->collectionId;
			}
		}

		return $ids;
	}

	/**
	 * @param array<int, array{items: array, nextCursor: ?array}> $levels
	 * @return array<string, array{items: array, nextCursor: ?array}>
	 */
	private static function keyByCollection(array $levels): array
	{
		$out = [];
		foreach ($levels as $collectionId => $level)
		{
			$out[(string)$collectionId] = $level;
		}

		return $out;
	}
}
