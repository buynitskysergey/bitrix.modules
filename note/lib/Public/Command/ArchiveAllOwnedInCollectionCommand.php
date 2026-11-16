<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Command;

use Bitrix\Main\Result;
use Bitrix\Note\Internal\Access\Service\CollectionAccessService;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Service\Bulk\BoundaryReparenter;
use Bitrix\Note\Internal\Service\Bulk\BulkAccessAggregator;
use Bitrix\Note\Internal\Service\Collaboration\PushNotificationService;
use Bitrix\Note\Internal\Service\History\EventLogService;
use Bitrix\Note\Internal\Service\Search\SearchIndexService;

/**
 * "Archive all my articles": archives every live document of one knowledge base authored by the
 * calling user. The set is resolved on the server over the whole base, never taken from the rows
 * the client happened to load, and it holds nothing but the caller's own documents — MANAGE is
 * still required on each of them, and the ones without it are reported as skippedByAccessCount.
 *
 * Somebody else's document under an archived one would become unreachable, so the whole boundary of
 * the set is re-hung in one pass before the first chunk is applied: every child outside the set
 * lands on the nearest ancestor that stays, the base root when there is none. A node skipped for
 * access stays in the tree, so it counts as such an ancestor.
 */
class ArchiveAllOwnedInCollectionCommand extends BulkArchiveDocumentsCommand
{
	private readonly int $collectionId;
	private readonly DocumentRepository $ownedRepository;
	private readonly BoundaryReparenter $boundaryReparenter;
	/** @var int[]|null */
	private ?array $ownedIds = null;
	/** @var int[]|null */
	private ?array $topNodeIds = null;

	public function __construct(
		int $collectionId,
		int $userId,
		?DocumentRepository $repository = null,
		?BoundaryReparenter $boundaryReparenter = null,
		?SearchIndexService $searchIndexService = null,
		?PushNotificationService $pushService = null,
		?BulkAccessAggregator $accessAggregator = null,
		?EventLogService $eventLogService = null,
	)
	{
		$repository ??= new DocumentRepository();

		// withNested: true switches the per-node re-hang of the base command off. The set is whole
		// subtrees of the caller's own nodes, and its boundary is re-hung in one pass instead.
		parent::__construct(
			$userId,
			[],
			true,
			$repository,
			null,
			$searchIndexService,
			$pushService,
			null,
			$accessAggregator,
			$eventLogService,
		);

		$this->collectionId = $collectionId;
		$this->ownedRepository = $repository;
		$this->boundaryReparenter = $boundaryReparenter ?? new BoundaryReparenter($repository);
	}

	protected function execute(): Result
	{
		// Archiving is a write into the base, so its state decides it: an archived base is read-only,
		// and an author-scoped archive gets no exception to that.
		CollectionAccessService::assertCollectionAcceptsWrites($this->collectionId);

		return parent::execute();
	}

	protected function resolveSelection(): array
	{
		$ids = $this->ownedIds();

		return [
			'ids' => $ids,
			'byCollection' => empty($ids) ? [] : [$this->collectionId => $ids],
			// The set is the whole base, so there is no selection cap to exceed and no "with nested"
			// toggle to offer: both answers are fixed.
			'limitExceeded' => false,
			'hasNested' => false,
			'rootsMeta' => [],
		];
	}

	protected function selectionRootIds(): array
	{
		if ($this->topNodeIds === null)
		{
			$ids = $this->ownedIds();
			$parentById = $this->ownedRepository->getParentMap($this->collectionId, $ids);
			$inSet = array_fill_keys($ids, true);

			$topNodeIds = [];
			foreach ($ids as $id)
			{
				$parentId = $parentById[$id] ?? null;
				if ($parentId === null || !isset($inSet[$parentId]))
				{
					$topNodeIds[] = $id;
				}
			}

			$this->topNodeIds = $topNodeIds;
		}

		return $this->topNodeIds;
	}

	protected function beforeApply(array $allowedIds): void
	{
		// The allowed set, not the resolved one: a node skipped for access keeps its place in the
		// tree, so the walk up has to be able to stop on it.
		$this->boundaryReparenter->rehang($this->collectionId, $this->userId, $allowedIds);
	}

	/**
	 * @return int[]
	 */
	private function ownedIds(): array
	{
		// Read once: the archive UPDATE that follows takes every id out of the live set, and the
		// history roots are still asked for afterwards.
		$this->ownedIds ??= $this->ownedRepository->getLiveOwnedCollectionDocumentIds(
			$this->collectionId,
			$this->userId,
		);

		return $this->ownedIds;
	}
}
