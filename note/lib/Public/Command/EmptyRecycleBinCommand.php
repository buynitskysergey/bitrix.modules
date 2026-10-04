<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Command;

use Bitrix\Main\Application;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;
use Bitrix\Note\Internal\Access\Service\DocumentAccessService;
use Bitrix\Note\Internal\Repository\RecycleBinRepository;
use Bitrix\Note\Internal\Service\RecycleBin\HardDeleteService;

class EmptyRecycleBinCommand extends AbstractCommand
{
	private const CHUNK_SIZE = 200;

	/**
	 * @param array<int, string> $accessCodes
	 */
	public function __construct(
		private readonly int $userId,
		private readonly array $accessCodes,
		private readonly RecycleBinRepository $recycleBinRepository = new RecycleBinRepository(),
		private readonly HardDeleteService $hardDeleteService = new HardDeleteService(),
	) {}

	protected function execute(): Result
	{
		$totalDeleted = 0;
		$cursor = null;

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

				$this->hardDeleteService->runPostCommitCleanup(
					$cleanupPayload['fileIds'],
					$cleanupPayload['documentIds'],
				);

				$totalDeleted += count($documentIds);
			}

			$cursor = $page['nextCursor'] ?? null;
		}
		while ($cursor !== null);

		// No HARD_DELETED lifecycle event for empty-recycle-bin: every document here already emitted DELETED
		// (trash) or ARCHIVED earlier, which the RAG connector turned into a delete — so the doc has already
		// left RAG and HARD_DELETED's fan-out would be an idempotent no-op. Emitting it only accumulated an
		// unbounded id list (the recycle bin is not bounded by the per-base cap) for zero effect. The single
		// HardDeleteDocumentCommand path still emits HARD_DELETED, so the connector case stays live.
		$result = new Result();
		$result->setData(['deletedCount' => $totalDeleted]);

		return $result;
	}
}
