<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Command;

use Bitrix\Main\Application;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;
use Bitrix\Note\Internal\Configuration;
use Bitrix\Note\Internal\Entity\History\DocumentVersion;
use Bitrix\Note\Internal\Exceptions\DocumentArchivedException;
use Bitrix\Note\Internal\Exceptions\DocumentHasUnsavedChangesException;
use Bitrix\Note\Internal\Exceptions\DocumentInRecycleBinException;
use Bitrix\Note\Internal\Exceptions\DocumentNotFoundException;
use Bitrix\Note\Public\Event\OnDocumentContentSettledEvent;
use Bitrix\Note\Internal\Service\DomainEventPublisher;
use Bitrix\Note\Internal\Model\DocumentTable;
use Bitrix\Note\Internal\Model\EventTable;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Repository\DocumentUpdateRepository;
use Bitrix\Note\Internal\Repository\DocumentVersionRepository;
use Bitrix\Main\Type\DateTime;
use Bitrix\Note\Internal\Service\Collaboration\DocumentLockService;
use Bitrix\Note\Internal\Service\Collaboration\PushNotificationService;
use Bitrix\Note\Internal\Service\History\EventLogService;
use Bitrix\Note\Internal\Service\Link\DocumentLinkIndexService;
use Bitrix\Note\Internal\Service\RecycleBin\RecycleBinFilter;
use Bitrix\Note\Internal\Service\Search\SearchIndexService;

class OverwriteDocumentContentCommand extends AbstractCommand
{
	// Wait this long for the shared 'compact' lock. Materialization and compaction hold it only for a
	// single sub-second write, so the overwrite virtually never blocks; a real timeout means genuine
	// contention and is surfaced as an error rather than silently proceeding unprotected.
	private const LOCK_TIMEOUT_SECONDS = 10;

	public function __construct(
		private readonly int $documentId,
		private readonly string $markdown,
		private readonly int $userId,
		private readonly bool $overwrite = false,
		private readonly ?string $title = null,
		// Identifier of the client operation this overwrite carries out, travelling on to the push so the
		// tab that asked for it can tell its own operation from a foreign write (see
		// PushNotificationService::sendDocumentContentOverwritten). Nothing here stores it: it lives in the
		// request and in the notification, and an overwrite nobody asked for simply has none.
		private readonly ?string $operationId = null,
		private readonly DocumentRepository $documentRepository = new DocumentRepository(),
		private readonly DocumentUpdateRepository $updateRepository = new DocumentUpdateRepository(),
		private readonly PushNotificationService $pushService = new PushNotificationService(),
		private readonly SearchIndexService $searchIndexService = new SearchIndexService(),
		private readonly RecycleBinFilter $recycleBinFilter = new RecycleBinFilter(),
		private readonly DomainEventPublisher $eventPublisher = new DomainEventPublisher(),
		private readonly DocumentVersionRepository $documentVersionRepository = new DocumentVersionRepository(),
		private readonly EventLogService $eventLogService = new EventLogService(),
		private readonly DocumentLinkIndexService $linkIndexService = new DocumentLinkIndexService(),
		private readonly DocumentLockService $lockService = new DocumentLockService(),
	) {}

	protected function execute(): Result
	{
		// [P3.T2] Serialize with materialization/compaction on the shared 'compact' lock so a concurrent
		// materialize cannot write the discarded CRDT snapshot back over the overwrite. The durable guard
		// against a *late* materialize (from a tab unaware of the overwrite) is the cursor watermark set
		// in writeContent(), not this lock.
		if (!$this->lockService->acquireLock($this->documentId, self::LOCK_TIMEOUT_SECONDS))
		{
			throw new \RuntimeException('Could not acquire the document lock for overwrite');
		}

		try
		{
			$collectionId = $this->writeContent();
		}
		finally
		{
			$this->lockService->releaseLock($this->documentId);
		}

		// [P3.T2] Both of these run outside the lock window on purpose. The link rebuild takes a named lock
		// of its own, and on production MySQL (5.6) a session holds at most one named lock at a time -
		// GET_LOCK silently releases the previous one - so calling it from under the 'compact' lock would
		// drop that lock long before the finally above, leaving the rest of the write unprotected while
		// claiming otherwise. Nothing out here needs the protection anyway: the content is committed.
		//
		// After the content commit and outside its transaction: the rebuild opens its own and absorbs its
		// own failures, so the overwrite cannot fail because of the index.
		$this->linkIndexService->rebuild($this->documentId);

		// EVENT-NOTE-02 `overwritten` - a single content-settled event; it also covers a title
		// change made in the same call (title is part of the materialized text and fingerprint).
		$this->eventPublisher->emitContentSettled(
			OnDocumentContentSettledEvent::OVERWRITTEN,
			$collectionId,
			$this->documentId,
		);

		return new Result();
	}

	/**
	 * The whole protected part of the overwrite: guards, the content transaction and the journal drain.
	 * Returns the document's collection id for the lifecycle event emitted after the lock is released.
	 */
	private function writeContent(): int
	{
		$document = $this->documentRepository->getMetaById(
			$this->documentId,
			['ID', 'COLLECTION_ID', 'TITLE', 'IS_ARCHIVED', 'CONTENT_FORMAT', 'UPDATED_AT'],
		);
		if ($document === null)
		{
			throw new DocumentNotFoundException();
		}

		if ($this->recycleBinFilter->isInRecycleBin($this->documentId))
		{
			throw new DocumentInRecycleBinException();
		}

		if ($document->getIsArchived())
		{
			throw new DocumentArchivedException();
		}

		$format = (string)$document->getContentFormat();
		$isCollaborative = $format === DocumentTable::CONTENT_FORMAT_YJS
			|| $format === DocumentTable::CONTENT_FORMAT_JSON;

		// Guard outside the transaction: do not open it just to roll back.
		if ($isCollaborative && !$this->overwrite && $this->updateRepository->hasAnyByDocumentId($this->documentId))
		{
			throw new DocumentHasUnsavedChangesException();
		}

		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$document->setMarkdown($this->markdown);
			if ($this->title !== null)
			{
				$document->setTitle($this->title);
			}
			$document->setUpdatedBy($this->userId);

			if ($isCollaborative)
			{
				// Demote the document to the plain-markdown format: the realtime CRDT
				// state becomes orphaned and uncommitted patches no longer apply.
				$document->setContentFormat(DocumentTable::CONTENT_FORMAT_MD);
				$document->setYjsState(null);
			}

			// [P3.T2] Journal high-water mark for the cursor. The overwrite replaces the whole text, so
			// every patch written so far is settled by definition and the cursor is PINNED to the top of
			// the range rather than clamped to some claimed value - this command has no incoming cursor to
			// believe or disbelieve (see DocumentRepository::pinMaterializationCursor).
			//
			// Read INSIDE the transaction and immediately before the drain below, which deletes the journal
			// whole rather than up to this id: a patch landing between the two would be destroyed while the
			// cursor stayed under it, and SavePatchCommand::readJournalBaseId would then report a waterline
			// below the level really cut away.
			//
			// Written ONLY for a document that was collaborative. A cursor means nothing on plain markdown -
			// there is no journal to measure and no materialization to gate - but it means a great deal to
			// SaveYjsStateCommand, which reads "format md with a cursor" as "this document was deliberately
			// demoted": the collaborative format comes back only under a baseline rebuilt from the text written
			// above, never under one a client was already holding. Pinning it for a document that was already
			// md would demand that same proof where there is nothing to protect - no CRDT state was discarded -
			// while conversion is how such a document starts a collaborative session at all.
			$journalWatermark = null;
			if ($isCollaborative)
			{
				$journalWatermark = DocumentRepository::pinMaterializationCursor(
					$this->updateRepository->getLastId($this->documentId),
					$this->documentRepository->getMaterializedUptoId($this->documentId),
				);

				$this->updateRepository->deleteByDocumentId($this->documentId);
			}

			// [P3.T2] Overwrite indexes both derived projections inline (search below + link rebuild after
			// commit), so the derived-stale flag stays 'N'. Content date is now. Unlike materialization,
			// overwrite goes through save() and deliberately moves UPDATED_AT.
			$document->setContentUpdatedAt(new DateTime());
			$document->setDerivedStale(false);
			if ($journalWatermark !== null)
			{
				$document->setMaterializedUptoId($journalWatermark);
			}

			$saveResult = $this->documentRepository->save($document);
			if (!$saveResult->isSuccess())
			{
				throw new \RuntimeException('Failed to save document: ' . implode(', ', $saveResult->getErrorMessages()));
			}

			// [P1.T1] Version + content_changed for every overwrite (REST-overwrite and restore
			// alike): the actor is always $this->userId, never a race-winning collab client.
			// Title-change-only overwrites do not get a separate title_changed — the content
			// event subsumes it (see $titleChanged usage below, which stays push-only).
			if (Configuration::isActivityEnabled())
			{
				$versionId = null;
				if ($this->markdown !== '')
				{
					$version = $this->documentVersionRepository->save(
						DocumentVersion::create($this->documentId, $this->markdown, (string)$document->getTitle(), $this->userId),
					);
					$versionId = $version->getId();
				}

				$this->eventLogService->record(
					EventTable::SCOPE_DOCUMENT,
					$this->documentId,
					'content_changed',
					$this->userId,
					$versionId,
					[$this->userId],
				);
			}

			$connection->commitTransaction();
		}
		catch (\Throwable $e)
		{
			$connection->rollbackTransaction();

			throw $e;
		}

		$documentId = $this->documentId;
		$userId = $this->userId;
		$overwrite = $this->overwrite;
		$operationId = $this->operationId;
		$searchIndexService = $this->searchIndexService;
		$pushService = $this->pushService;
		$collectionId = (int)$document->getCollectionId();
		$titleChanged = $this->title !== null;
		$newTitle = (string)$document->getTitle();
		// Both already persisted above — hand them to the indexer so it skips the re-read SELECT.
		$newMarkdown = $this->markdown;

		$this->pushService->dispatchAfterCommit(static function () use (
			$documentId,
			$collectionId,
			$userId,
			$overwrite,
			$operationId,
			$isCollaborative,
			$titleChanged,
			$newTitle,
			$newMarkdown,
			$searchIndexService,
			$pushService,
		): void {
			try
			{
				$searchIndexService->indexDocument($documentId, $newMarkdown, $newTitle);
			}
			catch (\Throwable)
			{
			}

			if ($isCollaborative)
			{
				$pushService->sendDocumentContentOverwritten($documentId, $userId, $overwrite, $operationId);
			}

			if ($titleChanged)
			{
				// Reuses documentUpdate path so sidebar/breadcrumbs/header all refresh via existing FE handlers.
				// No initiator skip: overwrite is an out-of-band REST write, so the initiator's own open
				// sessions must refresh too (same rationale as documentContentOverwritten above).
				$payload = [
					'documentId' => $documentId,
					'collectionId' => $collectionId,
					'title' => $newTitle,
				];
				$pushService->sendToCollection($collectionId, 'documentUpdate', $payload);
				$pushService->sendToDocument($documentId, 'documentUpdate', $payload);
			}
		});

		return $collectionId;
	}
}
