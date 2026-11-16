<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Command;

use Bitrix\Main\Application;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;
use Bitrix\Note\Internal\Access\Service\DocumentAccessService;
use Bitrix\Note\Internal\Repository\RecycleBinRepository;
use Bitrix\Note\Internal\Service\RecycleBin\HardDeleteCollectionService;
use Bitrix\Note\Internal\Service\RecycleBin\ModeratedTrashedCollections;
use Bitrix\Note\Internal\Service\RecycleBin\HardDeleteService;

class EmptyRecycleBinCommand extends AbstractCommand
{
	private const CHUNK_SIZE = 200;

	// One request takes away one slice of the bin. Deleting a knowledge base now sends every document
	// of it to the bin, so "everything the caller may hard-delete" can be tens of thousands of rows
	// with their files and index entries - far past what a single request may hold. What is left stays
	// in the bin, the caller is told the bin is not empty yet, and the cleanup agent still takes away
	// on its own whatever outlives the retention.
	private const BUDGET_SECONDS = 10.0;

	/**
	 * @param array<int, string> $accessCodes
	 */
	public function __construct(
		private readonly int $userId,
		private readonly array $accessCodes,
		private readonly RecycleBinRepository $recycleBinRepository = new RecycleBinRepository(),
		private readonly HardDeleteService $hardDeleteService = new HardDeleteService(),
		private readonly HardDeleteCollectionService $hardDeleteCollectionService = new HardDeleteCollectionService(),
		private readonly ModeratedTrashedCollections $trashedCollections = new ModeratedTrashedCollections(),
		private readonly float $budgetSeconds = self::BUDGET_SECONDS,
	) {}

	/**
	 * @return Result data: deletedCount, deletedCollectionCount and completed - false when the budget
	 *         ran out and part of the bin is still there
	 */
	protected function execute(): Result
	{
		$deadline = microtime(true) + $this->budgetSeconds;
		$totalDeleted = 0;
		$cursor = null;
		$completed = true;

		do
		{
			$page = DocumentAccessService::listRecycleBinRecordsForUser(
				$this->userId,
				$this->accessCodes,
				self::CHUNK_SIZE,
				$cursor,
			);
			$entryIds = $page['ids'];
			if (empty($entryIds))
			{
				break;
			}

			$records = $this->recycleBinRepository->getByIds($entryIds);
			$acl = DocumentAccessService::bulkComputeRecycleBinAcl($this->userId, array_values($records));

			$documentIds = [];
			foreach ($entryIds as $entryId)
			{
				$record = $records[(int)$entryId] ?? null;
				if ($record === null)
				{
					continue;
				}
				if (($acl[(int)$record->getId()]['canHardDelete'] ?? false) === true)
				{
					$documentIds[] = (int)$record->getDocumentId();
				}
			}

			if (!empty($documentIds))
			{
				$preparedLinkChange = $this->hardDeleteService->prepareDelete($documentIds);
				$connection = Application::getConnection();
				$connection->startTransaction();
				try
				{
					$cleanupPayload = $this->hardDeleteService->deleteByDocumentIds(
						$documentIds,
						$preparedLinkChange,
					);
					$connection->commitTransaction();
				}
				catch (\Throwable $e)
				{
					$connection->rollbackTransaction();
					throw $e;
				}

				$this->hardDeleteService->runPostCommitCleanup(
					$cleanupPayload['fileIds'],
					$cleanupPayload['documentIds'],
				);

				$totalDeleted += count($documentIds);
			}

			$cursor = $page['nextCursor'] ?? null;

			if ($cursor !== null && microtime(true) > $deadline)
			{
				$completed = false;

				break;
			}
		}
		while ($cursor !== null);

		// No HARD_DELETED lifecycle event for empty-recycle-bin: every document here already emitted DELETED
		// (trash) or ARCHIVED earlier, which the RAG connector turned into a delete — so the doc has already
		// left RAG and HARD_DELETED's fan-out would be an idempotent no-op. Emitting it only accumulated an
		// unbounded id list (the recycle bin is not bounded by the per-base cap) for zero effect. The single
		// HardDeleteDocumentCommand path still emits HARD_DELETED, so the connector case stays live.

		$deletedCollectionCount = 0;
		if ($completed)
		{
			// The bases wait for their own request: with the documents unfinished there is no budget
			// left for a base, and a base is the heavier half of the two.
			$collections = $this->purgeCollections($deadline);
			$deletedCollectionCount = $collections['purged'];
			$completed = $collections['completed'];
		}

		$result = new Result();
		$result->setData([
			'deletedCount' => $totalDeleted,
			'deletedCollectionCount' => $deletedCollectionCount,
			'completed' => $completed,
		]);

		return $result;
	}

	/**
	 * Second pass: the bases of the bin the caller may take away. A base goes whole even when part of
	 * its documents is invisible to the caller - otherwise a collection row would be left behind with
	 * nothing inside it.
	 *
	 * @return array{purged: int, completed: bool}
	 */
	private function purgeCollections(float $deadline): array
	{
		$purged = 0;
		foreach ($this->trashedCollections->iterate($this->userId, $this->accessCodes) as $collectionId)
		{
			if (microtime(true) > $deadline)
			{
				return ['purged' => $purged, 'completed' => false];
			}

			$result = $this->hardDeleteCollectionService->purge($collectionId, $deadline);
			if (!$result->isSuccess())
			{
				// A base being restored or purged elsewhere is locked and stays for whoever holds it.
				continue;
			}

			// The deadline stopped the base half-way: it keeps the rest of its documents and its place
			// in the bin, so the answer must not claim the bin is empty.
			if (($result->getData()['completed'] ?? true) === false)
			{
				return ['purged' => $purged, 'completed' => false];
			}

			$purged++;
		}

		return ['purged' => $purged, 'completed' => true];
	}
}
