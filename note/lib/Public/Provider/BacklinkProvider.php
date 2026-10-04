<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Provider;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\Type\DateTime;
use Bitrix\Note\Internal\Access\PortalAdmin;
use Bitrix\Note\Internal\Access\Service\CollectionAccessService;
use Bitrix\Note\Internal\Access\Service\DocumentAccessService;
use Bitrix\Note\Internal\Exceptions\AccessDeniedException;
use Bitrix\Note\Internal\Model\CollectionTable;
use Bitrix\Note\Internal\Model\DocumentTable;
use Bitrix\Note\Internal\Repository\DocumentLinkRepository;

/**
 * [P3.T1 / ALG-05] Incoming links of a document: the counter for the widget badge and the paged
 * list behind it. Both walk the same chain — right on the target, then ONE keyset query over the
 * link index carrying the visibility predicate in its WHERE. A knowledge base description is
 * reported as a source of its own, flagged by `isCollectionDescription`.
 *
 * Rights used to be applied after the fetch, which forced a window loop with a pass budget: when a
 * user's visible sources were rare among hidden ones, the budget ran out before the first visible
 * row and the panel came back empty with nothing to page towards. The predicate now runs in the
 * database, so the page is whatever the query returns — the cursor is honest and the count is
 * exact up to its cap.
 *
 * Neither value is cached: both are personal, two users looking at the same document get
 * different numbers.
 */
class BacklinkProvider
{
	public const COUNT_CAP = 99;

	private const MAX_LIMIT = 50;
	private const DEFAULT_LIMIT = 20;

	public function __construct(
		private readonly DocumentProvider $documentProvider = new DocumentProvider(),
		private readonly DocumentLinkRepository $linkRepository = new DocumentLinkRepository(),
	) {}

	/**
	 * @return array{count: int, isCapped: bool}
	 * @throws AccessDeniedException target document is missing or not visible — the two are
	 *         deliberately not told apart
	 */
	public function getCount(int $documentId): array
	{
		$this->assertCanViewTarget($documentId);

		// One bounded query over already-visible rows: COUNT_CAP + 1 tells "there are more" apart
		// from "exactly a cap's worth", which is all the chip needs.
		$window = $this->linkRepository->listSourcesForCount(
			$documentId,
			self::COUNT_CAP,
			null,
			$this->buildVisibilityFilter(),
		);

		$total = count($window['rows']);

		// A zero here is a REAL zero — there is no budget left to run out, so the chip may honestly
		// hide itself. Capped now means only "at least COUNT_CAP visible sources exist".
		return [
			'count' => min($total, self::COUNT_CAP),
			'isCapped' => $window['hasNextPage'] || $total > self::COUNT_CAP,
		];
	}

	/**
	 * @param int|null $afterSourceId raw SOURCE_ID keyset cursor from a previous page (echoed back as received)
	 * @return array{
	 *   items: array<int, array{id: int, title: string, collectionId: int, collectionTitle: string, isCollectionDescription: bool, updatedAt: string}>,
	 *   nextCursor: ?int
	 * }
	 * @throws AccessDeniedException
	 */
	public function getSources(int $documentId, int $limit = self::DEFAULT_LIMIT, ?int $afterSourceId = null): array
	{
		$this->assertCanViewTarget($documentId);

		$limit = max(1, min(self::MAX_LIMIT, $limit));

		// Keyset by SOURCE_ID DESC [A4] over rows the database already filtered by rights, so one
		// query is a whole page: no window loop, and the +1 lookahead answers "is there more".
		$window = $this->linkRepository->listSources(
			$documentId,
			$limit,
			$afterSourceId !== null && $afterSourceId > 0 ? $afterSourceId : null,
			$this->buildVisibilityFilter(),
		);

		$rows = $window['rows'];

		return [
			'items' => $this->toItems($rows),
			// Honest cursor: null means the index really is exhausted. Every row here is visible, so
			// resuming after the last one skips nothing — there are no hidden rows left behind it.
			'nextCursor' => $window['hasNextPage'] && !empty($rows)
				? (int)$rows[count($rows) - 1]['SOURCE_ID']
				: null,
		];
	}

	/**
	 * The visibility predicate of the current user, or null for a portal admin (who sees every
	 * incoming link). Admins used to fall out of {@see DocumentAccessService::batchGetEffectiveLevels()}
	 * for free; with the check moved into SQL the bypass has to be spelled out here.
	 *
	 * Protected, like the target check above, so the paging arithmetic can be exercised without a
	 * live access layer.
	 */
	protected function buildVisibilityFilter(): ?ConditionTree
	{
		[$accessCodes, $userId] = $this->currentUserAccess();

		if (PortalAdmin::isAdmin($userId))
		{
			return null;
		}

		// Collection levels are resolved in PHP: getAllUserLevels() already folds the '*' policy and
		// the max(personal, policy) rule, so the query only needs the resulting id list.
		return DocumentAccessService::buildListVisibilityFilter(
			'SOURCE_ID',
			'SOURCE.COLLECTION_ID',
			DocumentAccessService::personalCodes($accessCodes),
			DocumentAccessService::accessibleCollectionIds(
				CollectionAccessService::getAllUserLevels($accessCodes)['effective'] ?? [],
			),
		);
	}

	/**
	 * Non-disclosure: a document nobody may see and a document that does not exist raise the same
	 * error, the way the mention resolver already answers.
	 *
	 * @throws AccessDeniedException
	 */
	protected function assertCanViewTarget(int $documentId): void
	{
		$ownership = $this->documentProvider->getOwnershipInfo($documentId);
		if ($ownership === null)
		{
			throw new AccessDeniedException();
		}

		$snapshot = DocumentAccessService::getCurrentUserSnapshot($documentId, $ownership['collectionId']);
		if (!$snapshot['canView'])
		{
			throw new AccessDeniedException();
		}
	}

	/**
	 * @return array{0: array<int, string>, 1: int}
	 */
	private function currentUserAccess(): array
	{
		$userId = (int)CurrentUser::get()->getId();

		return [CollectionAccessService::buildUserAccessCodes($userId), $userId];
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 * @return array<int, array{id: int, title: string, collectionId: int, collectionTitle: string, isCollectionDescription: bool, updatedAt: string}>
	 */
	private function toItems(array $rows): array
	{
		if (empty($rows))
		{
			return [];
		}

		$titles = $this->collectionTitles(array_map(
			static fn(array $row): int => (int)$row['COLLECTION_ID'],
			$rows,
		));

		$items = [];
		foreach ($rows as $row)
		{
			$collectionId = (int)$row['COLLECTION_ID'];
			$items[] = [
				'id' => (int)$row['SOURCE_ID'],
				'title' => (string)($row['TITLE'] ?? ''),
				'collectionId' => $collectionId,
				// Always exposed: whether the label is worth showing (own vs foreign knowledge
				// base) is the client's call.
				'collectionTitle' => $titles[$collectionId] ?? '',
				// The source is the knowledge base description, not a document of the tree: the client
				// shows the base itself and navigates to it instead of to a document that has no page.
				'isCollectionDescription' => (string)($row['IS_MAIN'] ?? '') === DocumentTable::IS_MAIN_YES,
				'updatedAt' => $this->updatedAt($row)->format('c'),
			];
		}

		return $items;
	}

	/**
	 * @param int[] $collectionIds
	 * @return array<int, string>
	 */
	private function collectionTitles(array $collectionIds): array
	{
		$ids = array_values(array_unique(array_filter($collectionIds, static fn(int $id): bool => $id > 0)));
		if (empty($ids))
		{
			return [];
		}

		$rows = CollectionTable::query()
			->setSelect(['ID', 'NAME'])
			->whereIn('ID', $ids)
			->fetchAll()
		;

		$titles = [];
		foreach ($rows as $row)
		{
			$titles[(int)$row['ID']] = (string)($row['NAME'] ?? '');
		}

		return $titles;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function updatedAt(array $row): DateTime
	{
		$value = $row['UPDATED_AT'] ?? null;

		return $value instanceof DateTime ? $value : new DateTime();
	}
}
