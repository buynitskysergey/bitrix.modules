<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Command;

use Bitrix\Main\Application;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;
use Bitrix\Main\SystemException;
use Bitrix\Main\Text\Emoji;
use Bitrix\Note\Internal\Configuration;
use Bitrix\Note\Internal\Entity\History\DocumentVersion;
use Bitrix\Note\Internal\Exceptions\DocumentArchivedException;
use Bitrix\Note\Internal\Exceptions\DocumentInRecycleBinException;
use Bitrix\Note\Public\Event\OnDocumentContentSettledEvent;
use Bitrix\Note\Internal\Model\DocumentTable;
use Bitrix\Note\Internal\Model\EventTable;
use Bitrix\Note\Internal\Service\DomainEventPublisher;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Repository\DocumentUpdateRepository;
use Bitrix\Note\Internal\Repository\DocumentVersionRepository;
use Bitrix\Note\Internal\Repository\EventRepository;
use Bitrix\Note\Internal\Service\Collaboration\DocumentLockService;
use Bitrix\Note\Internal\Service\History\EventLogService;
use Bitrix\Note\Internal\Service\RecycleBin\RecycleBinFilter;

class CompactDocumentCommand extends AbstractCommand
{
	public function __construct(
		private readonly int $documentId,
		private readonly int $userId,
		private readonly string $markdown,
		private readonly int $processedUpToId,
		private readonly ?string $yjsState = null,
		private readonly DocumentRepository $documentRepository = new DocumentRepository(),
		private readonly DocumentUpdateRepository $updateRepository = new DocumentUpdateRepository(),
		private readonly DocumentLockService $lockService = new DocumentLockService(),
		private readonly RecycleBinFilter $recycleBinFilter = new RecycleBinFilter(),
		private readonly DomainEventPublisher $eventPublisher = new DomainEventPublisher(),
		private readonly DocumentVersionRepository $documentVersionRepository = new DocumentVersionRepository(),
		private readonly EventLogService $eventLogService = new EventLogService(),
		private readonly EventRepository $eventRepository = new EventRepository(),
	) {}

	protected function execute(): Result
	{
		if (!$this->lockService->acquireLock($this->documentId))
		{
			// Concurrent compaction: MARKDOWN is not updated, so no content-settled event (Errata E1).
			$result = new Result();
			$result->setData(['locked' => true]);

			return $result;
		}

		try
		{
			$collectionId = $this->performCompaction();
		}
		finally
		{
			$this->lockService->releaseLock($this->documentId);
		}

		// Nothing was settled: the document is no longer collaborative (see performCompaction).
		if ($collectionId === null)
		{
			return new Result();
		}

		// [P4.T2] Derived projections (search + links) are no longer rebuilt inline here — compaction
		// raised IS_DERIVED_STALE='Y' via materializeProjection, and the freshness agent recomputes both
		// on its next tick. The content-settled event stays: RAG consumes it.
		$this->eventPublisher->emitContentSettled(
			OnDocumentContentSettledEvent::COMPACTED,
			$collectionId,
			$this->documentId,
		);

		return new Result();
	}

	/** Collection id for the lifecycle event, or NULL when the document is not collaborative any more. */
	private function performCompaction(): ?int
	{
		$document = $this->documentRepository->getMetaById(
			$this->documentId,
			// COLLECTION_ID is selected for the lifecycle event emitted after the compaction.
			['ID', 'COLLECTION_ID', 'TITLE', 'IS_ARCHIVED', 'CONTENT_FORMAT'],
			useCache: false,
		);
		if ($document === null)
		{
			throw new SystemException('Document not found');
		}

		if ($this->recycleBinFilter->isInRecycleBin($this->documentId))
		{
			throw new DocumentInRecycleBinException();
		}

		if ($document->getIsArchived())
		{
			throw new DocumentArchivedException();
		}

		// Same reason as in MaterializeDocumentCommand: the document is plain markdown, so there is no
		// collaborative state to settle - either an overwrite demoted it and the client asking to compact
		// has not learned of it, or it was never converted (the editor turns md into yjs on open, before
		// anything here is called). Here the stakes are higher than a stale projection - the snapshot
		// would put a CRDT state into YJS_STATE that nothing agreed on, where it would come alive the
		// moment anything returned the format to yjs.
		$format = (string)$document->getContentFormat();
		if ($format !== DocumentTable::CONTENT_FORMAT_YJS && $format !== DocumentTable::CONTENT_FORMAT_JSON)
		{
			return null;
		}

		// Read under the 'compact' lock held by execute(), so both values stay valid for the whole
		// transaction below. The journal head is the one the drain is measured against, hence a single
		// read shared by the clamp and by deleteUpToIdReturningAuthors.
		$journalLastId = $this->updateRepository->getLastId($this->documentId);
		$storedUptoId = $this->documentRepository->getMaterializedUptoId($this->documentId) ?? 0;
		$uptoId = DocumentRepository::clampMaterializationCursor(
			$this->processedUpToId,
			$journalLastId,
			$storedUptoId,
		);

		// A cursor below the stored watermark belongs to a client compacting a state everyone else has
		// moved past. The forward-only guard refuses its projection, and the destructive half has to be
		// refused with it: YJS_STATE carries no watermark of its own, so the older snapshot would silently
		// roll the CRDT back, and the drain would destroy the very patches needed to catch that snapshot
		// up again. An equal cursor is not behind - materialization gets there without touching the
		// journal or the snapshot, and refusing the drain there would let the journal grow without bound.
		$isBehindWatermark = $uptoId < $storedUptoId;

		// The drain is only safe when a snapshot arrives to replace what it destroys: the editor rebuilds
		// the document from YJS_STATE and never falls back to MARKDOWN, so cutting the journal without a
		// fresh snapshot leaves the previous one in charge and the next open silently discards the work the
		// cut patches carried. Without a snapshot the command degenerates into materialization - the
		// projection below is written all the same, and the next compaction that does carry one drains the
		// window it left behind. Orthogonal to the watermark guard: that one asks whether this client is
		// current, this one whether it brought the state to keep.
		//
		// An empty string is not a snapshot: the action signature is a nullable string, so a client that
		// sends nothing meaningful still arrives here with '' rather than null. Read as a snapshot it
		// would blank YJS_STATE and drain the journal behind it - everywhere else (getYjsState,
		// CollaborationProvider) an empty state already means "there is none".
		$hasSnapshot = $this->yjsState !== null && $this->yjsState !== '';

		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			// [P3.T1] Projection write goes through the shared forward-only guard (under the 'compact'
			// lock already held by execute()): it writes MARKDOWN + content date + cursor WITHOUT moving
			// UPDATED_AT, and raises the derived-stale flag. A false return means a concurrent write is
			// already at or ahead of this cursor: the projection is left as-is, without a rollback and
			// without stopping the compaction, whose journal drain and version below are gated on their
			// own terms.
			$this->documentRepository->materializeProjection($this->documentId, $this->markdown, $uptoId);

			$authorIds = [];
			if (!$isBehindWatermark && $hasSnapshot)
			{
				// YJS_STATE is persisted via updatePartial (which does not move UPDATED_AT), never save().
				$this->documentRepository->updatePartial($this->documentId, ['YJS_STATE' => $this->yjsState]);

				$deleteResult = $this->updateRepository->deleteUpToIdReturningAuthors($this->documentId, $uptoId);
				$authorIds = $deleteResult->getData()['authorIds'] ?? [];
			}

			// [P3.T1] Version gate on the split write contract. The old "markdown != previousMarkdown"
			// check no longer works: materialization keeps MARKDOWN fresh, so the pre-compact body
			// already equals the incoming text and the check would almost always read "unchanged". The
			// version is instead gated by three things, independent of materializeProjection's return
			// (that governs only the projection, never history):
			//   1. activity kill-switch on (a version without a matching event has no eventId);
			//   2. authorIds non-empty — patches were actually merged from the journal, i.e. there was
			//      a real edit (empty window → another editor already compacted this batch, ALG-01 dedup);
			//   3. the current text differs from the last settled state — suppresses a no-op version
			//      (compared against history, not MARKDOWN, which materialization keeps fresh).
			if (
				Configuration::isActivityEnabled()
				&& !empty($authorIds)
				&& $this->differsFromLatestVersion()
			)
			{
				$versionId = null;
				if ($this->markdown !== '')
				{
					$version = $this->documentVersionRepository->save(
						DocumentVersion::create($this->documentId, $this->markdown, $document->getTitle(), $this->userId),
					);
					$versionId = $version->getId();
				}

				$this->eventLogService->record(
					EventTable::SCOPE_DOCUMENT,
					$this->documentId,
					'content_changed',
					$this->userId,
					$versionId,
					$authorIds,
				);
			}

			$connection->commitTransaction();
		}
		catch (\Throwable $e)
		{
			$connection->rollbackTransaction();

			throw $e;
		}

		return (int)$document->getCollectionId();
	}

	/**
	 * True when the incoming text differs from the last settled state. Baseline is history rather than
	 * the current MARKDOWN, which materialization keeps equal to the incoming text and would always read
	 * as "unchanged".
	 *
	 * History is not only the version table. Clearing a document is recorded as a content_changed event
	 * WITHOUT a version (an empty version is deliberately not stored), so after "A → cleared" the newest
	 * version still says A. Comparing against it alone made the return to A read as no change at all,
	 * and that genuine edit produced neither a version nor a timeline entry. When the newest
	 * content_changed event carries no version, the last settled state was the empty text.
	 *
	 * The comparison itself runs on a fingerprint (byte length + MD5 computed by the database) so that a
	 * MEDIUMTEXT body is not pulled on every compaction just to be told it changed. Only when the cheap
	 * probe reports a match is the body read back, to settle the improbable collision exactly.
	 */
	private function differsFromLatestVersion(): bool
	{
		$latestEvent = $this->eventRepository->getLatestVersionIdOfType(
			EventTable::SCOPE_DOCUMENT,
			$this->documentId,
			'content_changed',
		);
		if ($latestEvent['found'] && $latestEvent['versionId'] === null)
		{
			return $this->markdown !== '';
		}

		$fingerprint = $this->documentVersionRepository->getLatestFingerprint($this->documentId);
		if ($fingerprint === null)
		{
			return true;
		}

		// The fingerprint describes the stored bytes, and MARKDOWN is stored emoji-encoded: compare
		// against the value the field's save modifier would produce, not against the raw string.
		$stored = Emoji::encode($this->markdown);
		if ($fingerprint['length'] !== strlen($stored) || $fingerprint['hash'] !== md5($stored))
		{
			return true;
		}

		$latest = $this->documentVersionRepository->getById($fingerprint['id']);

		return $latest === null || $latest->getMarkdown() !== $this->markdown;
	}
}
