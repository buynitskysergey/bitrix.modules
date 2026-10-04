<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Command;

use Bitrix\Note\Public\Event\OnDocumentLifecycleEvent;
use Bitrix\Main\Application;
use Bitrix\Note\Internal\Service\Bulk\BulkAccessAggregator;
use Bitrix\Note\Internal\Service\Bulk\SelectionResolver;
use Bitrix\Note\Internal\Service\RecycleBin\HardDeleteService;

/**
 * [FEAT-kb2-tree-bulk-archive / P4.T1 / API-04] Bulk hard-delete (empty from the recycle bin)
 * of a selection. Owner of the API-04 contract.
 *
 * The recycle section is flat, so no subtree expansion happens (withNested=false). Per-record
 * hard-delete rights are decided by BulkAccessAggregator via bulkComputeRecycleBinAcl
 * (canHardDelete): a live-collection entry needs MANAGE, an orphan needs trashedBy / portal
 * admin. Records the user cannot hard-delete land in skippedByAccessCount, they are not an error.
 *
 * Each chunk is deleted in its own transaction (DB-only cascade); the non-transactional cleanup
 * (CFile storage + search de-index) runs AFTER that chunk's commit so a rollback never leaves
 * orphaned files or a stale index — mirrors HardDeleteDocumentCommand / EmptyRecycleBinCommand.
 */
class BulkHardDeleteDocumentsCommand extends AbstractBulkCommand
{
	private readonly HardDeleteService $hardDeleteService;

	/**
	 * @param int[] $documentIds document ids the recycle-bin caller translated from recycleBinIds
	 */
	public function __construct(
		int $userId,
		array $documentIds,
		?HardDeleteService $hardDeleteService = null,
		?SelectionResolver $selectionResolver = null,
		?BulkAccessAggregator $accessAggregator = null,
	)
	{
		parent::__construct(
			$userId,
			$documentIds,
			SelectionResolver::SECTION_RECYCLE,
			false, // recycle entries are flat — hard-delete exactly the selected records
			$selectionResolver ?? new SelectionResolver(),
			$accessAggregator ?? new BulkAccessAggregator(),
		);
		$this->hardDeleteService = $hardDeleteService ?? new HardDeleteService();
	}

	protected function lifecycleReason(): ?string
	{
		return OnDocumentLifecycleEvent::HARD_DELETED;
	}

	protected function applyChunk(array $documentIds): array
	{
		$connection = Application::getConnection();
		$connection->startTransaction();
		try
		{
			$cleanupPayload = $this->hardDeleteService->deleteByDocumentIds($documentIds);
			$connection->commitTransaction();
		}
		catch (\Throwable $e)
		{
			$connection->rollbackTransaction();
			throw $e;
		}

		// Post-commit, outside any transaction: file storage + search index.
		$this->hardDeleteService->runPostCommitCleanup(
			$cleanupPayload['fileIds'],
			$cleanupPayload['documentIds'],
		);

		return ['processedIds' => $documentIds];
	}
}
