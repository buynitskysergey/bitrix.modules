<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Command;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;
use Bitrix\Note\Public\Event\OnDocumentLifecycleEvent;
use Bitrix\Note\Internal\Configuration;
use Bitrix\Note\Internal\Exceptions\MainDocumentOperationException;
use Bitrix\Note\Internal\Service\DomainEventPublisher;
use Bitrix\Note\Internal\Model\DocumentTable;
use Bitrix\Note\Internal\Model\EventTable;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Service\Analytics\AnalyticsDictionary;
use Bitrix\Note\Internal\Service\Analytics\AnalyticsService;
use Bitrix\Note\Internal\Service\Collaboration\PushNotificationService;
use Bitrix\Note\Internal\Service\Document\DocumentService;
use Bitrix\Note\Internal\Service\History\EventLogService;

class CreateDocumentCommand extends AbstractCommand
{
	private readonly int $collectionId;
	private readonly ?int $parentId;
	private readonly string $title;
	private readonly string $markdown;
	private readonly int $userId;
	private readonly string $contentFormat;
	private readonly DocumentService $documentService;
	private readonly PushNotificationService $pushService;
	private readonly DocumentRepository $repository;
	private readonly EventLogService $eventLogService;
	private readonly bool $notifyInitiator;
	private readonly DomainEventPublisher $eventPublisher;
	private readonly string $analyticsType;
	private bool $succeeded = false;

	public function __construct(
		int $collectionId,
		?int $parentId,
		string $title,
		string $markdown,
		int $userId,
		string $contentFormat = DocumentTable::CONTENT_FORMAT_YJS,
		?DocumentService $documentService = null,
		?PushNotificationService $pushService = null,
		?DocumentRepository $repository = null,
		// REST writes are out-of-band: notify the initiator's own sessions instead of skipping them.
		bool $notifyInitiator = false,
		?DomainEventPublisher $eventPublisher = null,
		?EventLogService $eventLogService = null,
		string $analyticsType = AnalyticsDictionary::TYPE_BK,
	)
	{
		$this->analyticsType = $analyticsType;
		$this->collectionId = $collectionId;
		$this->parentId = $parentId;
		$this->title = $title;
		$this->markdown = $markdown;
		$this->userId = $userId;
		$this->contentFormat = $contentFormat;
		$this->documentService = $documentService ?? new DocumentService();
		$this->pushService = $pushService ?? new PushNotificationService();
		$this->repository = $repository ?? new DocumentRepository();
		$this->notifyInitiator = $notifyInitiator;
		$this->eventPublisher = $eventPublisher ?? new DomainEventPublisher();
		$this->eventLogService = $eventLogService ?? new EventLogService();
	}

	protected function execute(): Result
	{
		// The main document lives outside the tree (PARENT_ID is always NULL) and can never
		// be a parent: nesting anything under it would break the "About the knowledge base" tab.
		if ($this->parentId !== null && $this->repository->isMainDocument($this->parentId))
		{
			throw new MainDocumentOperationException();
		}

		// documentService->create() is a single atomic ORM save; validation exceptions
		// (invalid collection/parent) are thrown before any write, so no wrapping
		// transaction is needed here — and one would only introduce a nested-rollback
		// hazard when this command runs inside an already-open transaction (e.g. tests).
		$document = $this->documentService->create(
			$this->collectionId,
			$this->parentId,
			$this->title,
			$this->markdown,
			$this->userId,
			$this->contentFormat,
		);

		if ($document !== null && Configuration::isActivityEnabled())
		{
			// Best-effort: a failure to log the event must not undo an already-created document.
			try
			{
				$this->eventLogService->record(
					EventTable::SCOPE_DOCUMENT,
					(int)$document->getId(),
					'created',
					$this->userId,
				);
			}
			catch (\Throwable)
			{
			}
		}

		if ($document !== null)
		{
			$this->emitDocumentCreate($document);
			$this->eventPublisher->emitLifecycle(
				OnDocumentLifecycleEvent::CREATED,
				(int)$document->getCollectionId(),
				[(int)$document->getId()],
			);
		}

		$this->succeeded = $document !== null;

		$result = new Result();
		$result->setData(['document' => $document]);

		return $result;
	}

	protected function afterRun(): void
	{
		// Plain (non-import) create carries no p1; importType is sent only on the import path.
		AnalyticsService::documentCreated($this->succeeded, null, $this->analyticsType);
	}

	private function emitDocumentCreate(\Bitrix\Note\Internal\Model\Document $document): void
	{
		$documentId = (int)$document->getId();
		$collectionId = (int)$document->getCollectionId();
		$parentId = $document->getParentId() !== null ? (int)$document->getParentId() : null;
		$title = (string)$document->getTitle();
		$position = (int)$document->getPosition();
		$initiatorUserId = $this->notifyInitiator ? null : $this->userId;
		$pushService = $this->pushService;

		// A document created under a granted subtree is already materialised into the ACL by
		// DocumentService::create, so its own id resolves the audience — and only that audience:
		// resolving through the parent would notify users whose grant does not reach this child.
		$pushService->notifyDocumentGrantees(
			$collectionId,
			[$documentId],
			$initiatorUserId,
			'documentCreate',
			[
				'documentId' => $documentId,
				'parentId' => $parentId,
				'title' => $title,
				'position' => $position,
				'hasChildren' => false,
			],
		);

		$pushService->dispatchAfterCommit(static function () use (
			$pushService,
			$documentId,
			$collectionId,
			$parentId,
			$title,
			$position,
			$initiatorUserId,
		): void {
			$pushService->sendToCollection(
				$collectionId,
				'documentCreate',
				[
					'documentId' => $documentId,
					'collectionId' => $collectionId,
					'parentId' => $parentId,
					'title' => $title,
					'position' => $position,
					'hasChildren' => false,
				],
				$initiatorUserId,
			);
		});
	}
}
