<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Command;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;
use Bitrix\Note\Internal\Access\Service\CollectionAccessService;
use Bitrix\Note\Internal\Model\Document;
use Bitrix\Note\Public\Event\OnDocumentLifecycleEvent;
use Bitrix\Note\Internal\Model\EventTable;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Service\Bulk\BulkAccessAggregator;
use Bitrix\Note\Internal\Service\Bulk\BulkOutcome;
use Bitrix\Note\Internal\Service\Bulk\SelectionResolver;
use Bitrix\Note\Internal\Service\Collaboration\PushNotificationService;
use Bitrix\Note\Internal\Service\Document\Position\PositionService;
use Bitrix\Note\Internal\Service\DomainEventPublisher;
use Bitrix\Note\Internal\Service\History\EventLogService;

/**
 * [FEAT-kb2-tree-bulk-archive / P4.T2 / API-05] Bulk move of selected roots (each with its
 * whole subtree) into a target collection/parent. Owner of the API-05 contract.
 *
 * This command does NOT extend AbstractBulkCommand: move has no chunk-level transaction (each
 * root is its own transaction inside PositionService, allowing partial success at a root
 * boundary), it needs the roots ordered deterministically for a consistent lock-acquisition
 * order rather than the chunking seam, and it must surface an out-of-band "invalid target for
 * every root" signal that the fixed AbstractBulkCommand::execute() cannot carry. It reuses the
 * same building blocks by composition (SelectionResolver, BulkAccessAggregator, BulkOutcome),
 * so the DTO-01 outcome shape is identical to the chunked heirs.
 *
 * Roots are selected as-is (no subtree expansion): PositionService cascades COLLECTION_ID onto
 * the whole subtree, so moving the root moves its descendants. A root whose target falls inside
 * its own subtree is skipped (reported, never rolls the run back); the single-root cycle guard
 * inside PositionService is the ultimate authority — the pre-check here only classifies the
 * skip precisely so the endpoint can tell an all-roots-invalid request from a partial success.
 */
class BulkMoveDocumentsCommand extends AbstractCommand
{
	private readonly int $userId;
	/** @var int[] */
	private readonly array $rootIds;
	private readonly int $targetCollectionId;
	private readonly ?int $targetParentId;
	private readonly SelectionResolver $selectionResolver;
	private readonly BulkAccessAggregator $accessAggregator;
	private readonly PositionService $positionService;
	private readonly DocumentRepository $repository;
	private readonly PushNotificationService $pushService;
	private readonly EventLogService $eventLogService;
	private readonly DomainEventPublisher $eventPublisher;

	/**
	 * @param int[] $documentIds selected root ids
	 */
	public function __construct(
		int $userId,
		array $documentIds,
		int $targetCollectionId,
		?int $targetParentId = null,
		?SelectionResolver $selectionResolver = null,
		?BulkAccessAggregator $accessAggregator = null,
		?PositionService $positionService = null,
		?DocumentRepository $repository = null,
		?PushNotificationService $pushService = null,
		?EventLogService $eventLogService = null,
		?DomainEventPublisher $eventPublisher = null,
	)
	{
		$this->userId = $userId;
		$this->rootIds = $documentIds;
		$this->targetCollectionId = $targetCollectionId;
		$this->targetParentId = $targetParentId !== null && $targetParentId > 0 ? $targetParentId : null;
		$this->selectionResolver = $selectionResolver ?? new SelectionResolver();
		$this->accessAggregator = $accessAggregator ?? new BulkAccessAggregator();
		$this->positionService = $positionService ?? new PositionService();
		$this->repository = $repository ?? new DocumentRepository();
		$this->pushService = $pushService ?? new PushNotificationService();
		$this->eventLogService = $eventLogService ?? new EventLogService();
		$this->eventPublisher = $eventPublisher ?? new DomainEventPublisher();
	}

	protected function execute(): Result
	{
		$result = new Result();

		// Move keeps only the selected roots (withNested=false): PositionService cascades the
		// subtree, so expanding here would double-count and break the deterministic root order.
		$resolution = $this->selectionResolver->resolve(
			$this->rootIds,
			SelectionResolver::SECTION_ACTIVE,
			false,
		);
		if ($resolution['limitExceeded'] === true)
		{
			$result->setData(['outcome' => BulkOutcome::limitExceeded()->toArray(), 'invalidTargetForAll' => false]);

			return $result;
		}

		$accessCodes = CollectionAccessService::buildUserAccessCodes($this->userId);
		$access = $this->accessAggregator->aggregate(
			$resolution['ids'],
			$resolution['byCollection'],
			SelectionResolver::SECTION_ACTIVE,
			CollectionAccessService::LEVEL_MANAGE,
			$this->userId,
			$accessCodes,
		);

		$metaById = [];
		foreach ($resolution['rootsMeta'] as $document)
		{
			$metaById[(int)$document->getId()] = $document;
		}

		$processed = 0;
		$cycleSkips = 0;
		$transientSkips = 0;
		$affectedCollectionIds = [];
		$movedIds = [];
		// EVENT-NOTE-01 payloads: from/to are per root, so they are collected here and emitted below.
		$moveEvents = [];

		// Deterministic order (collectionId, parentId, id) = a consistent branch-lock acquisition
		// order across roots from different branches, so a bulk run cannot deadlock against itself.
		foreach ($this->orderRoots($access['allowedIds'], $metaById) as $rootId)
		{
			$meta = $metaById[$rootId] ?? null;
			if ($meta === null)
			{
				$transientSkips++;

				continue;
			}

			$sourceCollectionId = (int)$meta->getCollectionId();
			if ($this->isTargetInsideSubtree($rootId, $sourceCollectionId))
			{
				$cycleSkips++;

				continue;
			}

			$moveResult = $this->positionService->move(
				$rootId,
				$this->targetCollectionId,
				$this->targetParentId,
				null,
				$this->userId,
			);
			if (!$moveResult->isSuccess())
			{
				// PositionService re-guards the cycle and validates the parent; any failure is a
				// per-root skip that must not roll the whole run back.
				$transientSkips++;

				continue;
			}

			$document = $moveResult->getData()['document'] ?? null;
			if (!$document instanceof Document)
			{
				// Raced out (e.g. deleted between resolve and move) — reported, not fatal.
				$transientSkips++;

				continue;
			}

			$processed++;
			$movedIds[] = $rootId;
			$affectedCollectionIds[$sourceCollectionId] = true;
			$affectedCollectionIds[$this->targetCollectionId] = true;

			$sourceParentId = $meta->getParentId() !== null ? (int)$meta->getParentId() : null;
			$movedCollectionId = (int)$document->getCollectionId();
			$movedParentId = $document->getParentId() !== null ? (int)$document->getParentId() : null;

			// A pure reorder (same collection AND same parent) leaves membership and content intact,
			// so it is a no-op for RAG — same rule as MoveDocumentCommand::emitMoveDomainEvent.
			if ($sourceCollectionId !== $movedCollectionId || $sourceParentId !== $movedParentId)
			{
				$moveEvents[] = [
					'rootId' => $rootId,
					'from' => ['collectionId' => $sourceCollectionId, 'parentId' => $sourceParentId],
					'to' => ['collectionId' => $movedCollectionId, 'parentId' => $movedParentId],
				];
			}
		}

		$outcome = BulkOutcome::fromAccess(count($access['skippedByAccessIds']))
			->withChunk($processed, 0, $cycleSkips + $transientSkips)
		;

		// The whole request is unsatisfiable only when every allowed root was a target-in-subtree
		// cycle — the endpoint turns that into NOTE_INVALID_TARGET instead of a zero-count success.
		$invalidTargetForAll = $processed === 0 && $transientSkips === 0 && $cycleSkips > 0;

		if ($processed > 0)
		{
			// Batch history parity with MoveDocumentCommand; only actually-moved roots, no live-push.
			$this->eventLogService->recordMany(EventTable::SCOPE_DOCUMENT, $movedIds, 'moved', $this->userId);

			try
			{
				$this->emitRefetch(array_keys($affectedCollectionIds));
			}
			catch (\Throwable)
			{
			}

			// EVENT-NOTE-01 `moved`, one event per moved root: from/to describe that root's old and
			// new location while the event carries its whole subtree (already rewritten to the target
			// collection by the move) — the contract MoveDocumentCommand emits for a single move.
			// Best-effort: the moves are already committed, so a failure here must not fail the run.
			try
			{
				foreach ($moveEvents as $moveEvent)
				{
					$subtreeIds = $this->repository->getSubtreeIds($moveEvent['rootId'], $moveEvent['to']['collectionId']);
					if (empty($subtreeIds))
					{
						$subtreeIds = [$moveEvent['rootId']];
					}

					$this->eventPublisher->emitLifecycle(
						OnDocumentLifecycleEvent::MOVED,
						$moveEvent['to']['collectionId'],
						$subtreeIds,
						$moveEvent['from'],
						$moveEvent['to'],
					);
				}
			}
			catch (\Throwable)
			{
			}
		}

		$result->setData([
			'outcome' => $outcome->toArray(),
			'invalidTargetForAll' => $invalidTargetForAll,
		]);

		return $result;
	}

	/**
	 * @param int[] $allowedIds
	 * @param array<int, Document> $metaById
	 * @return int[]
	 */
	private function orderRoots(array $allowedIds, array $metaById): array
	{
		$ordered = array_values(array_map('intval', $allowedIds));
		usort($ordered, function (int $a, int $b) use ($metaById): int {
			$collationA = $this->orderKey($a, $metaById);
			$collationB = $this->orderKey($b, $metaById);

			return $collationA <=> $collationB;
		});

		return $ordered;
	}

	/**
	 * @param array<int, Document> $metaById
	 * @return array{0: int, 1: int, 2: int} [collectionId, parentId, id] order tuple
	 */
	private function orderKey(int $id, array $metaById): array
	{
		$meta = $metaById[$id] ?? null;
		$collectionId = $meta !== null ? (int)$meta->getCollectionId() : 0;
		$parentId = $meta !== null && $meta->getParentId() !== null ? (int)$meta->getParentId() : 0;

		return [$collectionId, $parentId, $id];
	}

	private function isTargetInsideSubtree(int $rootId, int $sourceCollectionId): bool
	{
		if ($this->targetParentId === null)
		{
			// A collection root can never sit inside a moved subtree.
			return false;
		}

		$subtreeIds = $this->repository->getSubtreeIds($rootId, $sourceCollectionId, true);

		return in_array($this->targetParentId, array_map('intval', $subtreeIds), true);
	}

	/**
	 * @param int[] $collectionIds
	 */
	private function emitRefetch(array $collectionIds): void
	{
		$initiatorUserId = $this->userId;
		$pushService = $this->pushService;

		$pushService->dispatchAfterCommit(static function () use ($pushService, $collectionIds, $initiatorUserId): void {
			foreach ($collectionIds as $collectionId)
			{
				$collectionId = (int)$collectionId;
				if ($collectionId <= 0)
				{
					continue;
				}

				$pushService->sendToCollection(
					$collectionId,
					'documentMove',
					['collectionId' => $collectionId, 'requestRefetch' => true],
					$initiatorUserId,
				);
			}
		});
	}
}
