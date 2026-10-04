<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Command;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;
use Bitrix\Note\Internal\Configuration;
use Bitrix\Note\Internal\Exceptions\DocumentArchivedException;
use Bitrix\Note\Internal\Exceptions\DocumentInRecycleBinException;
use Bitrix\Note\Public\Event\OnDocumentContentSettledEvent;
use Bitrix\Note\Internal\Model\EventTable;
use Bitrix\Note\Internal\Service\DomainEventPublisher;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Service\Collaboration\PushNotificationService;
use Bitrix\Note\Internal\Service\Document\DocumentService;
use Bitrix\Note\Internal\Service\History\EventLogService;
use Bitrix\Note\Internal\Service\Link\BacklinkLifecycleNotifier;
use Bitrix\Note\Internal\Service\RecycleBin\RecycleBinFilter;

class UpdateDocumentCommand extends AbstractCommand
{
	private readonly DocumentService $documentService;
	private readonly DocumentRepository $repository;
	private readonly RecycleBinFilter $recycleBinFilter;
	private readonly PushNotificationService $pushService;
	private readonly DomainEventPublisher $eventPublisher;
	private readonly EventLogService $eventLogService;
	private readonly BacklinkLifecycleNotifier $backlinkNotifier;

	public function __construct(
		private readonly int $id,
		private readonly ?string $title,
		private readonly int $userId,
		?DocumentService $documentService = null,
		?DocumentRepository $repository = null,
		?RecycleBinFilter $recycleBinFilter = null,
		?PushNotificationService $pushService = null,
		// REST writes are out-of-band: notify the initiator's own sessions instead of skipping them.
		private readonly bool $notifyInitiator = false,
		?DomainEventPublisher $eventPublisher = null,
		?EventLogService $eventLogService = null,
		?BacklinkLifecycleNotifier $backlinkNotifier = null,
	)
	{
		$this->documentService = $documentService ?? new DocumentService();
		$this->repository = $repository ?? new DocumentRepository();
		$this->recycleBinFilter = $recycleBinFilter ?? new RecycleBinFilter();
		$this->pushService = $pushService ?? new PushNotificationService();
		$this->eventPublisher = $eventPublisher ?? new DomainEventPublisher();
		$this->eventLogService = $eventLogService ?? new EventLogService();
		$this->backlinkNotifier = $backlinkNotifier ?? new BacklinkLifecycleNotifier();
	}

	protected function execute(): Result
	{
		if ($this->recycleBinFilter->isInRecycleBin($this->id))
		{
			throw new DocumentInRecycleBinException();
		}

		$existing = $this->repository->getById($this->id);
		if ($existing !== null && $existing->getIsArchived())
		{
			throw new DocumentArchivedException();
		}

		// Uncached previous title for accurate change detection (see getTitleById): the cached $existing
		// above may hold a stale title, which would mis-fire (or miss) the titleChanged event.
		// Read the uncached previous title ONLY when a title change is actually requested ($this->title !== null)
		// — otherwise DocumentService::update leaves the title untouched, titleChanged can never fire, and the
		// uncached SELECT would be wasted on every non-rename update.
		$previousTitle = $this->title !== null ? $this->repository->getTitleById($this->id) : null;

		$oldTitle = $existing?->getTitle();

		// documentService->update() is a single atomic ORM save; validation exceptions are
		// thrown before any write, so no wrapping transaction is needed here — one would
		// only introduce a nested-rollback hazard when this command runs inside an
		// already-open transaction (e.g. tests).
		$document = $this->documentService->update(
			id: $this->id,
			title: $this->title,
			markdown: null,
			userId: $this->userId,
		);

		// title_changed fires only when the persisted title actually changed — not on
		// every UpdateDocumentCommand call (e.g. $title === null means no title update
		// was requested at all, and old/new titles would be identical).
		if ($document !== null && Configuration::isActivityEnabled() && $document->getTitle() !== $oldTitle)
		{
			// Best-effort: a failure to log the event must not undo an already-saved document.
			try
			{
				$this->eventLogService->record(
					EventTable::SCOPE_DOCUMENT,
					(int)$document->getId(),
					'title_changed',
					$this->userId,
				);
			}
			catch (\Throwable)
			{
			}
		}

		if ($document !== null)
		{
			$documentId = (int)$document->getId();
			$collectionId = (int)$document->getCollectionId();
			$title = (string)$document->getTitle();
			$initiatorUserId = $this->notifyInitiator ? null : $this->userId;
			$pushService = $this->pushService;

			// Only a real title change is visible in a tree, and the grantee fan-out costs an ACL
			// lookup — do not pay it for updates that leave the title as it was.
			if ($title !== $oldTitle)
			{
				$pushService->notifyDocumentGrantees(
					$collectionId,
					[$documentId],
					$initiatorUserId,
					'documentUpdate',
					['documentId' => $documentId, 'title' => $title],
				);

				// [D1] The new title is what a backlink popover shows for this source; nudge the
				// documents it links to so they re-read it. Same notifier the archive/trash/restore
				// commands use; it is best-effort inside, so a stale name never fails a saved rename.
				$this->backlinkNotifier->sourcesChanged([$documentId]);
			}

			$pushService->dispatchAfterCommit(static function () use (
				$pushService,
				$documentId,
				$collectionId,
				$title,
				$initiatorUserId,
			): void {
				$payload = [
					'documentId' => $documentId,
					'collectionId' => $collectionId,
					'title' => $title,
				];
				$pushService->sendToCollection($collectionId, 'documentUpdate', $payload, $initiatorUserId);
				$pushService->sendToDocument($documentId, 'documentUpdate', $payload, $initiatorUserId);
			});

			// EVENT-NOTE-02 `titleChanged` — only when the title actually changed
			// (title is part of the materialized text and fingerprint).
			if ($this->title !== null && $previousTitle !== $title)
			{
				$this->eventPublisher->emitContentSettled(
					OnDocumentContentSettledEvent::TITLE_CHANGED,
					$collectionId,
					$documentId,
				);
			}
		}

		$result = new Result();
		$result->setData(['document' => $document]);

		return $result;
	}
}
