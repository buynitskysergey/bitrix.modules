<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Command;

use Bitrix\Main\Application;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Note\Public\Event\OnDocumentLifecycleEvent;
use Bitrix\Note\Internal\Service\DomainEventPublisher;
use Bitrix\Note\Internal\Repository\RecycleBinRepository;
use Bitrix\Note\Internal\Service\Collaboration\PushNotificationService;
use Bitrix\Note\Internal\Service\RecycleBin\HardDeleteService;

class HardDeleteDocumentCommand extends AbstractCommand
{
	public const ERROR_RECORD_MISSING = 'NOTE_RECYCLE_BIN_RECORD_MISSING';

	public function __construct(
		private readonly int $entryId,
		private readonly int $userId,
		private readonly RecycleBinRepository $recycleBinRepository = new RecycleBinRepository(),
		private readonly HardDeleteService $hardDeleteService = new HardDeleteService(),
		private readonly PushNotificationService $pushService = new PushNotificationService(),
		private readonly DomainEventPublisher $eventPublisher = new DomainEventPublisher(),
	) {}

	protected function execute(): Result
	{
		$record = $this->recycleBinRepository->getById($this->entryId);
		if ($record === null)
		{
			$result = new Result();
			$result->addError(new Error('Recycle-bin record not found', self::ERROR_RECORD_MISSING));

			return $result;
		}

		$documentId = (int)$record->getDocumentId();

		$connection = Application::getConnection();
		$connection->startTransaction();
		try
		{
			$cleanupPayload = $this->hardDeleteService->deleteByDocumentIds([$documentId]);
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

		$this->emitHardDelete($documentId);
		$this->eventPublisher->emitLifecycle(
			OnDocumentLifecycleEvent::HARD_DELETED,
			null,
			[$documentId],
		);

		$result = new Result();
		$result->setData(['documentId' => $documentId]);

		return $result;
	}

	private function emitHardDelete(int $documentId): void
	{
		$initiatorUserId = $this->userId;
		$pushService = $this->pushService;

		$pushService->dispatchAfterCommit(static function () use ($pushService, $documentId, $initiatorUserId): void {
			$pushService->sendToDocument(
				$documentId,
				'documentHardDelete',
				['documentId' => $documentId],
				$initiatorUserId,
			);
		});
	}
}
