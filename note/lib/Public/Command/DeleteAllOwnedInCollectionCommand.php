<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Command;

use Bitrix\Main\Result;
use Bitrix\Note\Internal\Access\Service\CollectionAccessService;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Repository\RecycleBinRepository;
use Bitrix\Note\Internal\Service\Bulk\BoundaryReparenter;
use Bitrix\Note\Internal\Service\Bulk\BulkAccessAggregator;
use Bitrix\Note\Internal\Service\Bulk\SelectionResolver;
use Bitrix\Note\Internal\Service\Collaboration\PushNotificationService;
use Bitrix\Note\Internal\Service\History\EventLogService;
use Bitrix\Note\Internal\Service\Search\SearchIndexService;

/**
 * "Delete all my articles": moves every live document of one knowledge base authored by the calling
 * user to the recycle bin. Same shape as {@see ArchiveAllOwnedInCollectionCommand} — the set is
 * resolved on the server, MANAGE is still required per document, and the boundary of the set is
 * re-hung in one pass before the first chunk, so somebody else's document never follows the caller's
 * own into the bin.
 */
class DeleteAllOwnedInCollectionCommand extends BulkDeleteDocumentsCommand
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
		?RecycleBinRepository $recycleBinRepository = null,
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
			SelectionResolver::SECTION_ACTIVE,
			true,
			$repository,
			$recycleBinRepository,
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
		// Emptying is a write into the base, so its state decides it: an archived base is read-only,
		// and an author-scoped delete gets no exception to that.
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
		// Read once: the recycle-bin rows that follow take every id out of the live set, and the
		// history roots are still asked for afterwards.
		$this->ownedIds ??= $this->ownedRepository->getLiveOwnedCollectionDocumentIds(
			$this->collectionId,
			$this->userId,
		);

		return $this->ownedIds;
	}
}
