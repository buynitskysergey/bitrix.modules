<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Provider;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Main\SystemException;
use Bitrix\Note\Internal\Access\Service\CollectionAccessService;
use Bitrix\Note\Internal\Access\Service\DocumentAccessService;
use Bitrix\Note\Internal\Access\PortalAdmin;
use Bitrix\Note\Internal\Entity\Search\SearchResult;
use Bitrix\Note\Internal\Entity\Search\SearchResultCollection;
use Bitrix\Note\Internal\Model\Collection;
use Bitrix\Note\Internal\Model\CollectionTable;
use Bitrix\Note\Internal\Repository\CollectionRepository;
use Bitrix\Note\Internal\Repository\DocumentSearchRepository;
use Bitrix\Note\Internal\Service\Document\DocumentCardMetaResolver;
use Bitrix\Note\Internal\Service\Search\SearchQueryBuilder;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Public\Provider\Param\Search\SearchQuery;

final class SearchProvider
{
	public function __construct(
		private readonly DocumentSearchRepository $repository = new DocumentSearchRepository(),
		private readonly SearchQueryBuilder $queryBuilder = new SearchQueryBuilder(),
		private readonly CollectionRepository $collectionRepository = new CollectionRepository(),
		private readonly DocumentRepository $documentRepository = new DocumentRepository(),
		private readonly DocumentCardMetaResolver $cardMetaResolver = new DocumentCardMetaResolver(),
	) {}

	/**
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	/**
	 * @param int|null $collectionId restricts the search to one collection; a collection the caller
	 *                               cannot see yields an empty answer rather than a refusal
	 */
	public function search(
		SearchQuery $query,
		Pager $pager,
		bool $withSnippets = true,
		?int $collectionId = null,
	): SearchResultCollection
	{
		$boolean = $this->queryBuilder->prepareBooleanQuery($query->getQuery());
		if ($boolean === '')
		{
			return new SearchResultCollection();
		}

		$userId = (int)CurrentUser::get()->getId();
		$accessCodes = $userId > 0 ? CollectionAccessService::buildUserAccessCodes($userId) : [];
		$allowedCollectionIds = $this->resolveAllowedCollections();
		if ($collectionId !== null)
		{
			// Narrowing to one collection also narrows the grant branch away: a document reachable only
			// through a personal grant is not "in this collection" as far as the caller is concerned.
			$allowedCollectionIds = in_array($collectionId, $allowedCollectionIds, true) ? [$collectionId] : [];
			if (empty($allowedCollectionIds))
			{
				return new SearchResultCollection();
			}
		}

		$hasDocumentGrants = $collectionId === null
			&& !empty($accessCodes)
			&& !PortalAdmin::isCurrentUserAdmin()
			&& DocumentAccessService::userHasAnyGrant($accessCodes);

		if (empty($allowedCollectionIds) && !$hasDocumentGrants)
		{
			return new SearchResultCollection();
		}

		$tokens = $this->queryBuilder->extractTokens($query->getQuery());
		$snippetTokens = $withSnippets ? $tokens : [];

		$limit = $pager->getLimit();
		$collection = $this->repository->searchByQuery(
			$boolean,
			$allowedCollectionIds,
			$limit + 1,
			$pager->getOffset(),
			$snippetTokens,
			$hasDocumentGrants ? $accessCodes : null,
			$hasDocumentGrants,
			$tokens,
		);

		if ($collection->count() > $limit)
		{
			$collection->markHasMore(true);
			$collection->trimTo($limit);
		}

		$this->enrichResults($collection);

		return $collection;
	}

	/**
	 * Hydrates each result with collection title (when collection-VIEW exists) and author meta.
	 * Privacy: when sharedAccess=true, jsonSerialize already omits collectionId/Title; we still
	 * skip the collection-title resolve for those rows to avoid a leaky payload via getters.
	 */
	private function enrichResults(SearchResultCollection $collection): void
	{
		if ($collection->isEmpty())
		{
			return;
		}

		$documentIds = [];
		$collectionIds = [];
		foreach ($collection as $result)
		{
			/** @var SearchResult $result */
			$documentIds[$result->getDocumentId()] = true;
			if (!$result->isSharedAccess() && $result->getCollectionId() > 0)
			{
				$collectionIds[$result->getCollectionId()] = true;
			}
		}

		$collectionTitles = $this->resolveCollectionTitles(array_keys($collectionIds));

		$documents = $this->documentRepository->getMetaByIds(
			array_keys($documentIds),
			['ID', 'CREATED_BY'],
		);
		$authorIdByDocumentId = [];
		foreach ($documents as $document)
		{
			$authorIdByDocumentId[(int)$document->getId()] = (int)$document->getCreatedBy();
		}
		$cardMeta = $this->cardMetaResolver->resolve($authorIdByDocumentId);

		$collection->transform(static function (SearchResult $result) use ($collectionTitles, $cardMeta): SearchResult {
			$title = $result->isSharedAccess()
				? ''
				: (string)($collectionTitles[$result->getCollectionId()] ?? '');
			$author = $cardMeta[$result->getDocumentId()]['author'] ?? null;

			return $result->withMeta($title, $author);
		});
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
			->exec();

		$out = [];
		while ($row = $rows->fetch())
		{
			$out[(int)$row['ID']] = (string)($row['NAME'] ?? '');
		}

		return $out;
	}

	/**
	 * @return int[]
	 */
	private function resolveAllowedCollections(): array
	{
		$userId = (int)CurrentUser::get()->getId();
		if ($userId <= 0)
		{
			return [];
		}

		if (PortalAdmin::isCurrentUserAdmin())
		{
			return array_map(
				static fn (Collection $collection): int => (int)$collection->getId(),
				$this->collectionRepository->getList(),
			);
		}

		$accessCodes = CollectionAccessService::buildUserAccessCodes($userId);
		$levels = CollectionAccessService::getAllUserLevels($accessCodes);

		$allowed = [];
		foreach (($levels['effective'] ?? []) as $cid => $level)
		{
			if ($level >= CollectionAccessService::LEVEL_VIEW)
			{
				$allowed[] = (int)$cid;
			}
		}

		return $allowed;
	}
}
