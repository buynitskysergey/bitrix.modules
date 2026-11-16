<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\ExternalAccess;

use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Note\Internal\Model\DocumentTable;
use Bitrix\Note\Internal\Repository\DocumentLinkRepository;
use Bitrix\Note\Internal\Service\Link\BacklinkCountLimit;

/**
 * Documents of the scope that link to a document of the scope.
 *
 * A twin of BacklinkProvider rather than its subclass: the production answer carries
 * `collectionTitle` and `isCollectionDescription`, and `toItems()` is private, so the shape here is
 * built rather than cleaned out of a wider one.
 *
 * The whole difference with the production provider is the predicate. Instead of the rights of the
 * current user it is the scope of the link: a source outside the knowledge base of the link is not
 * merely hidden from the list, it must not be knowable at all - a title is enough to tell that
 * someone else's document exists.
 *
 * The scope is wired in at construction and there is no method without it, exactly as in
 * ScopedDocumentReader: a knowledge base passed as an argument is a knowledge base a caller can
 * pass wrongly, and the mistake would be silent - a list of foreign sources looks like a valid
 * answer. The service is built from the link context, so a caller has nothing to get wrong.
 */
final class PublicBacklinkService
{
	private const DEFAULT_LIMIT = 20;
	private const MAX_LIMIT = 50;

	public function __construct(
		private readonly PublicScope $scope,
		private readonly DocumentLinkRepository $linkRepository = new DocumentLinkRepository(),
	) {}

	/**
	 * @return array{items: array<int, array{id: int, title: string, updatedAt: string}>, nextCursor: ?int}
	 */
	public function getSources(
		int $documentId,
		int $limit = self::DEFAULT_LIMIT,
		?int $afterSourceId = null,
	): array
	{
		$limit = max(1, min(self::MAX_LIMIT, $limit));

		$window = $this->linkRepository->listSources(
			$documentId,
			$limit,
			$afterSourceId !== null && $afterSourceId > 0 ? $afterSourceId : null,
			$this->buildVisibilityFilter(),
		);

		$rows = $window['rows'];

		$items = [];
		foreach ($rows as $row)
		{
			$items[] = [
				'id' => (int)$row['SOURCE_ID'],
				'title' => (string)($row['TITLE'] ?? ''),
				'updatedAt' => $row['UPDATED_AT']->format('c'),
			];
		}

		return [
			// Every row of the page passed the predicate, so resuming after the last one skips
			// nothing: there are no rows hidden behind it.
			'items' => $items,
			'nextCursor' => $window['hasNextPage'] && !empty($rows)
				? (int)$rows[count($rows) - 1]['SOURCE_ID']
				: null,
		];
	}

	/**
	 * @return array{count: int, isCapped: bool}
	 */
	public function getCount(int $documentId): array
	{
		$window = $this->linkRepository->listSourcesForCount(
			$documentId,
			BacklinkCountLimit::CAP,
			null,
			$this->buildVisibilityFilter(),
		);

		$total = count($window['rows']);

		return [
			'count' => min($total, BacklinkCountLimit::CAP),
			'isCapped' => $window['hasNextPage'] || $total > BacklinkCountLimit::CAP,
		];
	}

	/**
	 * Everything a visitor may be told a link from: inside the knowledge base of the link and not
	 * the hidden main document of it.
	 *
	 * The archive and the recycle bin are not spelled out here - `listSources()` and
	 * `listSourcesForCount()` exclude both themselves, for every caller alike. `IS_MAIN` they do not:
	 * the production list reports the description of a knowledge base as a source on purpose, and a
	 * visitor has no such node at all.
	 *
	 * RecycleBinFilter and MainDocumentFilter are deliberately not used, although the same condition
	 * is theirs: both write a bare column of DocumentTable, while a source is reached here through
	 * the `SOURCE` reference of DocumentLinkTable, so their condition would land on the wrong entity.
	 */
	private function buildVisibilityFilter(): ConditionTree
	{
		return (new ConditionTree())
			->where('SOURCE.COLLECTION_ID', $this->scope->getCollectionId())
			->where('SOURCE.IS_MAIN', DocumentTable::IS_MAIN_NO)
		;
	}
}
