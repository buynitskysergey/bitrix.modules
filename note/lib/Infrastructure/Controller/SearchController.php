<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Controller;

use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\Note\Infrastructure\Controller\ActionFilter\NoteAccess;
use Bitrix\Note\Public\Provider\ContentList\LevelProvider;
use Bitrix\Note\Public\Provider\ContentList\SectionLevelPreloader;
use Bitrix\Note\Public\Provider\ContentList\SectionProvider;
use Bitrix\Note\Public\Provider\Param\Search\SearchQuery;
use Bitrix\Note\Public\Provider\SearchProvider;

final class SearchController extends Controller
{
	private const QUICK_SEARCH_LIMIT = 5;
	private const MIN_QUERY_LENGTH = 3;
	private const MAX_QUERY_LENGTH = 1000;

	public function getDefaultPreFilters(): array
	{
		return array_merge(
			parent::getDefaultPreFilters(),
			[
				new NoteAccess(),
			],
		);
	}

	public function searchAction(
		string $query,
		PageNavigation $navigation,
		SearchProvider $provider,
	): ?array
	{
		$trimmed = mb_substr(trim($query), 0, self::MAX_QUERY_LENGTH);
		if ($trimmed === '')
		{
			$this->addError(new Error(Loc::getMessage('NOTE_SEARCH_QUERY_EMPTY')));

			return null;
		}

		if (mb_strlen($trimmed) < self::MIN_QUERY_LENGTH)
		{
			$this->addError(new Error(Loc::getMessage('NOTE_SEARCH_QUERY_TOO_SHORT')));

			return null;
		}

		$collection = $provider->search(
			query: new SearchQuery($trimmed),
			pager: Pager::buildFromPageNavigation($navigation),
		);

		return [
			'items' => iterator_to_array($collection),
			'hasMore' => $collection->hasMore(),
		];
	}

	/**
	 * Knowledge bases the query hits, with their counters. Grouping is done here and not
	 * over whatever landed on a page: sliced off a flat ranking, one knowledge base would appear on
	 * the first page and again on the third. The list of bases is paged by its place in the ranking,
	 * the same offset the content of a section is paged by - relevance has no key to run a keyset on.
	 *
	 * The content of the first sections travels with the list, and it is paged by the same size the
	 * caller then asks the next pages with: a first page bigger than the rest is a page the surface
	 * has no cursor for.
	 *
	 * @param array{offset: int}|null $cursor
	 */
	public function sectionsAction(
		string $query,
		int $limit = LevelProvider::DEFAULT_LIMIT,
		?array $cursor = null,
	): ?array
	{
		$userId = (int)$this->getCurrentUser()->getId();
		if ($userId <= 0)
		{
			return null;
		}

		$trimmed = $this->normalizeQuery($query);
		if ($trimmed === null)
		{
			return null;
		}

		$payload = (new SectionProvider())->getSearchSections($userId, $trimmed, $cursor);
		$payload['levels'] = SectionLevelPreloader::forSearch($userId, $trimmed, $payload['sections'], $limit);

		return $payload;
	}

	/**
	 * One page inside a search section. A null collectionId asks for the technical section -
	 * hits reachable only per document, whose knowledge base has no name to show the viewer.
	 *
	 * The cursor of the search is an offset and not the keyset of the other surfaces: relevance has no
	 * date key to run a keyset on, and a shape of its own is what keeps the two from being confused.
	 *
	 * @param array{offset: int}|null $cursor
	 */
	public function levelAction(
		string $query,
		?int $collectionId = null,
		?array $cursor = null,
		int $limit = LevelProvider::DEFAULT_LIMIT,
	): ?array
	{
		$userId = (int)$this->getCurrentUser()->getId();
		if ($userId <= 0)
		{
			return null;
		}

		$trimmed = $this->normalizeQuery($query);
		if ($trimmed === null)
		{
			return null;
		}

		return (new LevelProvider())->getSearchLevel($userId, $trimmed, $collectionId, $cursor, $limit);
	}

	/**
	 * The first page of several search sections at once - the sections the reader scrolled to, past
	 * the ones whose content arrived with the list. Sections come up open, so asking for them one by
	 * one is one request per section, each with a bootstrap of the core of its own.
	 *
	 * The answer carries `levels` in the shape the section list carries it, so the client reads both
	 * with the same parser. The page size is the caller's, as it is for a single level: the next
	 * pages are asked for by the same size, and the cursor here is an offset into the ranking.
	 *
	 * @param int[] $collectionIds no more than SectionLevelPreloader::MAX_SEARCH_BATCH_COLLECTIONS
	 *        answer - a shorter batch than the other surfaces allow, because a section of the search
	 *        is priced in the texts of its documents and not in rows of an index
	 * @return array{levels: array<string, array{items: array, nextCursor: ?array}>}|null
	 */
	public function levelsAction(
		string $query,
		array $collectionIds,
		int $limit = LevelProvider::DEFAULT_LIMIT,
	): ?array
	{
		$userId = (int)$this->getCurrentUser()->getId();
		if ($userId <= 0)
		{
			return null;
		}

		$trimmed = $this->normalizeQuery($query);
		if ($trimmed === null)
		{
			return null;
		}

		return ['levels' => SectionLevelPreloader::forSearchCollections($userId, $trimmed, $collectionIds, $limit)];
	}

	/**
	 * @return string|null null when an error has already been added to the response
	 */
	private function normalizeQuery(string $query): ?string
	{
		$trimmed = mb_substr(trim($query), 0, self::MAX_QUERY_LENGTH);
		if ($trimmed === '')
		{
			$this->addError(new Error(Loc::getMessage('NOTE_SEARCH_QUERY_EMPTY')));

			return null;
		}

		if (mb_strlen($trimmed) < self::MIN_QUERY_LENGTH)
		{
			$this->addError(new Error(Loc::getMessage('NOTE_SEARCH_QUERY_TOO_SHORT')));

			return null;
		}

		return $trimmed;
	}

	public function quickSearchAction(
		string $query,
		SearchProvider $provider,
	): ?array
	{
		$trimmed = mb_substr(trim($query), 0, self::MAX_QUERY_LENGTH);
		if ($trimmed === '' || mb_strlen($trimmed) < self::MIN_QUERY_LENGTH)
		{
			return ['items' => []];
		}

		$collection = $provider->search(
			query: new SearchQuery($trimmed),
			pager: new Pager(self::QUICK_SEARCH_LIMIT),
			withSnippets: false,
		);

		return ['items' => iterator_to_array($collection)];
	}
}
