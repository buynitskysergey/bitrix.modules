<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Read;

use Bitrix\Note\Infrastructure\Rest\V3\Access\EntityState;
use Bitrix\Note\Internal\Access\Service\CollectionVisibilityService;
use Bitrix\Note\Internal\Access\Service\DocumentAccessService;
use Bitrix\Note\Internal\Model\Document;
use Bitrix\Note\Internal\Repository\CollectionRepository;
use Bitrix\Note\Internal\Repository\DocumentAccessRepository;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Repository\RecycleBinRepository;
use Bitrix\Rest\V3\Exception\EntityNotFoundException;

/**
 * Every document the caller reaches, one page at a time.
 *
 * Visibility is an OR - the knowledge bases the caller holds, plus the documents granted to them
 * personally - and no index serves an OR in a single order, so the answer is assembled from three
 * streams read one after another: bases, then personal grants, then the documents whose base is
 * gone. Each stream is ordered by the keyset its own index gives away, and the page simply carries
 * on into the next stream when the one before it runs out, so a page may hold rows of two streams
 * and the cursor has to name the stream it stopped in.
 *
 * Nothing overlaps between the streams: the stream of grants drops the documents of the bases the
 * first stream has already named, and a document in the recycle bin is never reached through a
 * grant of its own - the bin is governed by the right to the base as a whole.
 */
final class DocumentListReader
{
	private const META_SELECT = [
		'ID', 'COLLECTION_ID', 'PARENT_ID', 'TITLE', 'POSITION', 'IS_ARCHIVED', 'IS_MAIN',
		'CREATED_BY', 'UPDATED_BY', 'CREATED_AT', 'UPDATED_AT', 'CONTENT_UPDATED_AT',
	];

	/**
	 * Floor of a portion. A limit of one would otherwise turn a run of rows the walk drops into a
	 * round of queries per row.
	 */
	private const MIN_PORTION = 50;

	/**
	 * Portions one call reads before it answers with what it has. The walk drops rows in PHP that
	 * the SQL of a stream knows nothing about - a grant pointing inside a base the caller reads
	 * anyway, a state the filter did not ask for - so without a budget a single call walks every
	 * grant the caller holds, four queries per portion, however small the page is.
	 *
	 * The budget is spent on the rows a stream looked at and not on the rows it handed over, which
	 * is what makes it a ceiling rather than a wish: a stream that drops rows inside its own query
	 * would otherwise walk the whole ACL and report a cost of zero.
	 */
	private const MAX_PORTIONS = 8;

	/** @var array<int, Document> metas of the rows this page kept, read once and used twice */
	private array $metaMemo = [];

	public function __construct(
		private readonly DocumentRepository $documents = new DocumentRepository(),
		private readonly RecycleBinRepository $recycleBin = new RecycleBinRepository(),
		private readonly CollectionRepository $collections = new CollectionRepository(),
		private readonly DocumentAccessRepository $grants = new DocumentAccessRepository(),
	) {}

	/**
	 * @throws EntityNotFoundException when the caller names a knowledge base it may not see
	 */
	public function list(DocumentListQuery $query, int $userId): ReadResult
	{
		$this->metaMemo = [];

		$scope = $this->scopeOf($query, $userId);
		$walk = $this->walk($query, $scope);
		$picked = $walk['rows'];

		$hasMore = count($picked) > $query->limit;
		$page = $hasMore ? array_slice($picked, 0, $query->limit) : $picked;
		// A walk stopped by its budget hands back where it stopped: the page is short of the limit,
		// and a null cursor here would read as the end of the list and lose everything behind it.
		$next = $hasMore ? $this->cursorOf($page[count($page) - 1]) : $walk['resume'];
		if ($page === [] && $next === null)
		{
			return new ReadResult();
		}

		return new ReadResult($this->readModels($page, $query), $next);
	}

	/**
	 * Who the caller is, said once for the whole walk: the bases they may read, the codes their
	 * personal grants are written against, and whether the orphans of the bin are theirs alone.
	 *
	 * Which bases those are is not decided here - {@see CollectionVisibilityService} answers it for
	 * every listing at once, recycle bin and portal administrator included. A caller bound by no
	 * list is the administrator, and the other two streams read that from the same answer: there is
	 * no grant of theirs to match and no owner to pin the orphans to.
	 *
	 * @return array{baseIds: int[]|null, codes: string[], orphanUserId: int|null}
	 */
	private function scopeOf(DocumentListQuery $query, int $userId): array
	{
		$baseIds = CollectionVisibilityService::readableIds($userId, $query->wants(EntityState::Trashed));
		$isAdmin = $baseIds === null;

		if ($query->collectionId !== null && $query->collectionId !== 0)
		{
			$named = $baseIds === null
				? $this->collections->exists($query->collectionId)
				: in_array($query->collectionId, $baseIds, true)
			;
			if (!$named)
			{
				throw new EntityNotFoundException($query->collectionId);
			}

			$baseIds = [$query->collectionId];
		}

		return [
			'baseIds' => $baseIds,
			'codes' => $isAdmin ? [] : DocumentAccessService::personalGrantStreamCodes($userId),
			'orphanUserId' => $isAdmin ? null : $userId,
		];
	}

	/**
	 * The streams this query is answered from, in the order they are read.
	 *
	 * @param array{baseIds: int[]|null, codes: string[], orphanUserId: int|null} $scope
	 * @return string[]
	 */
	private function streamsOf(DocumentListQuery $query, array $scope): array
	{
		if ($query->collectionId === 0)
		{
			// The documents with no base at all, which only the recycle bin holds.
			return $query->wants(EntityState::Trashed) ? [DocumentListCursor::STREAM_ORPHANS] : [];
		}

		if ($query->collectionId !== null)
		{
			return [DocumentListCursor::STREAM_COLLECTIONS];
		}

		$streams = [DocumentListCursor::STREAM_COLLECTIONS];
		if ($scope['baseIds'] !== null && !$query->wantsOnly(EntityState::Trashed))
		{
			// A grant of its own reaches no further than the bin door, so a page asked for the
			// trashed state alone has nothing to find here - and a caller bound by no base is
			// already holding everything a grant of theirs could point at.
			$streams[] = DocumentListCursor::STREAM_GRANTS;
		}
		if ($query->wants(EntityState::Trashed))
		{
			$streams[] = DocumentListCursor::STREAM_ORPHANS;
		}

		return $streams;
	}

	/**
	 * Rows of the page and one more, which is what tells the walk there is a next page at all - and,
	 * when the budget of the call ran out first, the place the next call carries on from.
	 *
	 * @param array{baseIds: int[]|null, codes: string[], orphanUserId: int|null} $scope
	 * @return array{
	 *     rows: array<int, array{stream: string, id: int, collectionId: int|null}>,
	 *     resume: array{stream: string, collectionId: int|null, id: int}|null,
	 * }
	 */
	private function walk(DocumentListQuery $query, array $scope): array
	{
		$streams = $this->streamsOf($query, $scope);
		$startAt = 0;
		if ($query->cursor !== null)
		{
			$startAt = array_search($query->cursor->stream, $streams, true);
			if ($startAt === false)
			{
				// The cursor names a stream this query is not answered from - the client changed the
				// filter halfway through the walk, and there is nothing left to carry on with.
				return ['rows' => [], 'resume' => null];
			}
		}

		$wanted = $query->limit + 1;
		$budget = self::MAX_PORTIONS * max($wanted, self::MIN_PORTION);
		$scanned = 0;
		$picked = [];
		$resume = null;

		foreach (array_slice($streams, $startAt) as $index => $stream)
		{
			$after = ($index === 0 && $query->cursor !== null)
				? ['collectionId' => $query->cursor->collectionId, 'id' => $query->cursor->id]
				: null
			;
			$round = 0;

			while (count($picked) < $wanted)
			{
				$need = $wanted - count($picked);
				// The first round of a stream asks for exactly what the page is short of - which is
				// all an ordinary page ever needs. A second round means rows were dropped, and from
				// there the walk reads by the portion so that a run of them costs rounds by the
				// portion and not by the row.
				$asked = $round === 0 ? $need : max($need, self::MIN_PORTION);
				$portion = $this->fetchPortion(
					$stream,
					$scope,
					$query,
					$after,
					$asked,
					max($budget - $scanned, 1),
				);
				$round++;
				// Rows looked at, not rows handed over. A stream that drops inside its own query -
				// the grants cancelled by an explicit deny - would otherwise cost the call an
				// unbounded scan and the budget not a single unit of itself.
				$scanned += $portion['scanned'];

				if ($portion['after'] !== null)
				{
					$after = $portion['after'];
				}

				foreach ($this->keep($stream, $portion['rows'], $scope, $query) as $row)
				{
					$picked[] = $row;
				}

				if ($portion['exhausted'])
				{
					// The stream ran out inside this portion: a short page here is its end and not a
					// page cut short by what the walk dropped.
					break;
				}

				if ($scanned >= $budget)
				{
					if ($after !== null)
					{
						// Every row between the last one kept and here was looked at and dropped, so
						// the place the scan reached is a page start and nothing is skipped by
						// resuming there.
						$resume = $this->cursorOf([
							'stream' => $stream,
							'id' => $after['id'],
							'collectionId' => $after['collectionId'],
						]);
					}

					break 2;
				}
			}

			if (count($picked) >= $wanted)
			{
				break;
			}
		}

		return ['rows' => $picked, 'resume' => $resume];
	}

	/**
	 * One portion of a stream, and what it cost to get: the rows it hands over, the rows it looked
	 * at on the way, where it stopped looking and whether it stopped because the stream ended.
	 *
	 * The three are the same number for two of the streams, and they are not for the grants: the
	 * ACL page reads past the grants an explicit deny cancels, so it is the only one that can look
	 * at a thousand rows and answer with none.
	 *
	 * @param array{baseIds: int[]|null, codes: string[], orphanUserId: int|null} $scope
	 * @param array{collectionId: int|null, id: int}|null $after
	 * @param int $scanBudget rows the portion may look at, whatever is left of the call's budget
	 * @return array{
	 *     rows: array<int, array{id: int, collectionId: int|null}>,
	 *     scanned: int,
	 *     after: array{collectionId: int|null, id: int}|null,
	 *     exhausted: bool,
	 * }
	 */
	private function fetchPortion(
		string $stream,
		array $scope,
		DocumentListQuery $query,
		?array $after,
		int $limit,
		int $scanBudget,
	): array
	{
		if ($stream === DocumentListCursor::STREAM_GRANTS)
		{
			$page = $this->grants->listGrantedDocumentIdsPage(
				$scope['codes'],
				$after['id'] ?? null,
				$limit,
				$scanBudget,
			);

			return [
				'rows' => $this->flat($page['ids']),
				'scanned' => $page['scanned'],
				'after' => $page['lastId'] === null
					? null
					: ['collectionId' => null, 'id' => $page['lastId']]
				,
				'exhausted' => $page['exhausted'],
			];
		}

		$rows = match ($stream)
		{
			DocumentListCursor::STREAM_COLLECTIONS => $this->collectionsPortion($scope, $query, $after, $limit),
			DocumentListCursor::STREAM_ORPHANS => $this->flat(
				$this->recycleBin->listOrphanDocumentPage($scope['orphanUserId'], $after['id'] ?? null, $limit),
			),
			default => [],
		};
		$last = $rows === [] ? null : $rows[count($rows) - 1];

		return [
			'rows' => $rows,
			// Either stream names a row in its own query or never looks at it, so what it scanned is
			// what it came back with.
			'scanned' => count($rows),
			'after' => $last === null
				? null
				: ['collectionId' => $last['collectionId'], 'id' => $last['id']]
			,
			'exhausted' => count($rows) < $limit,
		];
	}

	/**
	 * @param array{baseIds: int[]|null, codes: string[], orphanUserId: int|null} $scope
	 * @param array{collectionId: int|null, id: int}|null $after
	 * @return array<int, array{id: int, collectionId: int|null}>
	 */
	private function collectionsPortion(array $scope, DocumentListQuery $query, ?array $after, int $limit): array
	{
		// Asked for the bin alone, the page is read from the bin itself: the same rows taken by
		// scanning the documents for bin references cost two orders of magnitude more.
		if ($query->wantsOnly(EntityState::Trashed))
		{
			return $this->recycleBin->listByCollectionsPage($scope['baseIds'], $after, $limit);
		}

		return $this->documents->listByCollectionsPage(
			$scope['baseIds'],
			array_map(static fn(EntityState $state): string => $state->value, $query->states),
			$after,
			$limit,
		);
	}

	/**
	 * @param int[] $documentIds
	 * @return array<int, array{id: int, collectionId: int|null}>
	 */
	private function flat(array $documentIds): array
	{
		return array_map(
			static fn(int $documentId): array => ['id' => $documentId, 'collectionId' => null],
			$documentIds,
		);
	}

	/**
	 * Which rows of a portion belong on the page, and which stream each of them came from.
	 *
	 * Only the stream of grants drops anything: the two others are already exactly what they are
	 * allowed to be, while a grant says nothing about a document whose base the caller reads anyway,
	 * about one lying in the recycle bin, and about the description of a base.
	 *
	 * @param array<int, array{id: int, collectionId: int|null}> $portion
	 * @param array{baseIds: int[]|null, codes: string[], orphanUserId: int|null} $scope
	 * @return array<int, array{stream: string, id: int, collectionId: int|null}>
	 */
	private function keep(string $stream, array $portion, array $scope, DocumentListQuery $query): array
	{
		$rows = [];
		foreach ($portion as $row)
		{
			$rows[] = ['stream' => $stream, 'id' => $row['id'], 'collectionId' => $row['collectionId']];
		}

		if ($stream !== DocumentListCursor::STREAM_GRANTS)
		{
			return $rows;
		}

		$ids = array_column($rows, 'id');
		$metas = $this->metaByIds($ids);
		$binned = $this->recycleBin->filterTrashedDocumentIds($ids);
		// A caller bound by no base never reads this stream, so the list is always a list here.
		$visibleBases = array_flip($scope['baseIds'] ?? []);
		DocumentAccessService::primeCollectionStates(
			array_map(static fn(Document $meta): int => (int)$meta->getCollectionId(), $metas),
		);

		$kept = [];
		foreach ($rows as $row)
		{
			$meta = $metas[$row['id']] ?? null;
			if ($meta === null || $meta->getIsMain() || isset($binned[$row['id']]))
			{
				continue;
			}

			$collectionId = (int)$meta->getCollectionId();
			if (isset($visibleBases[$collectionId]))
			{
				// Already named by the stream of bases: a document reachable both ways is answered once.
				continue;
			}

			// The state of the base closes the document the same way it closes it for the read of a
			// single one: a grant of its own never reaches into a base lying in the recycle bin,
			// whatever the bin says about the document itself.
			if (!DocumentAccessService::collectionStateAllowed($collectionId, [DocumentAccessService::STATE_ARCHIVED]))
			{
				continue;
			}

			$state = $meta->getIsArchived() ? EntityState::Archived : EntityState::Live;
			if ($query->wants($state))
			{
				$this->metaMemo[$row['id']] = $meta;
				$kept[] = $row;
			}
		}

		return $kept;
	}

	/**
	 * @param array<int, array{stream: string, id: int, collectionId: int|null}> $page
	 * @return DocumentReadModel[]
	 */
	private function readModels(array $page, DocumentListQuery $query): array
	{
		$ids = array_column($page, 'id');
		$metas = $this->metaByIds($ids);
		$binned = $this->needsBinProbe($query, $page)
			? $this->recycleBin->filterTrashedDocumentIds($ids)
			: []
		;

		$items = [];
		foreach ($page as $row)
		{
			$meta = $metas[$row['id']] ?? null;
			if ($meta === null)
			{
				// The row left the table between the two reads; the page is short by one rather than
				// carrying a document nothing is known about any more.
				continue;
			}

			$isOrphan = $row['stream'] === DocumentListCursor::STREAM_ORPHANS;
			$items[] = $this->readModel(
				$meta,
				$row['stream'],
				$this->stateOf($row, $meta, $query, $binned),
				$isOrphan,
			);
		}

		return $items;
	}

	/**
	 * Whether the bin has to be asked which rows of the page are in it. The stream of bases is the
	 * only one that can carry both kinds at once, and even it only when the filter asked for both.
	 *
	 * @param array<int, array{stream: string, id: int, collectionId: int|null}> $page
	 */
	private function needsBinProbe(DocumentListQuery $query, array $page): bool
	{
		if (!$query->wants(EntityState::Trashed) || $query->wantsOnly(EntityState::Trashed))
		{
			return false;
		}

		return in_array(DocumentListCursor::STREAM_COLLECTIONS, array_column($page, 'stream'), true);
	}

	/**
	 * @param array{stream: string, id: int, collectionId: int|null} $row
	 * @param array<int, true> $binned
	 */
	private function stateOf(array $row, Document $meta, DocumentListQuery $query, array $binned): EntityState
	{
		$outsideBin = $meta->getIsArchived() ? EntityState::Archived : EntityState::Live;

		return match ($row['stream'])
		{
			// An orphan is in the bin by definition, and the stream of grants never reaches into it.
			DocumentListCursor::STREAM_ORPHANS => EntityState::Trashed,
			DocumentListCursor::STREAM_GRANTS => $outsideBin,
			default => $query->wantsOnly(EntityState::Trashed) || isset($binned[$row['id']])
				? EntityState::Trashed
				: $outsideBin
			,
		};
	}

	private function readModel(
		Document $meta,
		string $stream,
		EntityState $state,
		bool $isOrphan,
	): DocumentReadModel
	{
		// A document reached through a grant of its own says nothing about the base holding it: that
		// is privacy, and the read of a single document keeps the same silence.
		$sharedAccess = $stream === DocumentListCursor::STREAM_GRANTS;

		return new DocumentReadModel(
			id: (int)$meta->getId(),
			collectionId: $sharedAccess ? null : (int)$meta->getCollectionId(),
			parentId: $meta->getParentId() !== null ? (int)$meta->getParentId() : null,
			title: (string)$meta->getTitle(),
			// The heaviest column of the table, and never part of a page: the list answers with the
			// metainformation of a document, note.document.get with the document.
			markdown: '',
			position: (int)$meta->getPosition(),
			createdBy: (int)$meta->getCreatedBy(),
			updatedBy: (int)$meta->getUpdatedBy(),
			createdAt: $meta->getCreatedAt(),
			updatedAt: $meta->getUpdatedAt(),
			state: $state->value,
			contentUpdatedAt: $meta->getContentUpdatedAt(),
			isArchived: $state === EntityState::Archived,
			isTrashed: $state === EntityState::Trashed,
			isOrphan: $isOrphan,
		);
	}

	/**
	 * Metas of a set of rows. The stream of grants asks for a whole portion to decide what it keeps
	 * and remembers only what it kept, so the page built afterwards reads nothing twice while the
	 * rows the walk dropped - of which there may be a portion after portion - are not held on to.
	 *
	 * @param int[] $ids
	 * @return array<int, Document>
	 */
	private function metaByIds(array $ids): array
	{
		$byId = [];
		$missing = [];
		foreach ($ids as $id)
		{
			if (isset($this->metaMemo[$id]))
			{
				$byId[$id] = $this->metaMemo[$id];
			}
			else
			{
				$missing[] = $id;
			}
		}

		foreach ($this->documents->getMetaByIds($missing, self::META_SELECT) as $meta)
		{
			$byId[(int)$meta->getId()] = $meta;
		}

		return $byId;
	}

	/**
	 * @param array{stream: string, id: int, collectionId: int|null} $row
	 * @return array{stream: string, collectionId: int|null, id: int}
	 */
	private function cursorOf(array $row): array
	{
		return [
			'stream' => $row['stream'],
			// Only the stream of bases is ordered by two keys; in the others a base would be a key
			// nothing is compared against.
			'collectionId' => $row['stream'] === DocumentListCursor::STREAM_COLLECTIONS
				? $row['collectionId']
				: null
			,
			'id' => $row['id'],
		];
	}
}
