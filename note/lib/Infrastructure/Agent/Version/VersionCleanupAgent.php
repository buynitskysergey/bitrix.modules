<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Agent\Version;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Type\DateTime;
use Bitrix\Note\Internal\Configuration;
use Bitrix\Note\Internal\Repository\DocumentFileLinkRepository;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Repository\DocumentUpdateRepository;
use Bitrix\Note\Internal\Repository\DocumentVersionRepository;
use Bitrix\Note\Internal\Service\Collaboration\DocumentLockService;
use Bitrix\Note\Internal\Service\DocumentFileCleanupService;
use Bitrix\Note\Internal\Service\File\FileReachabilityService;

final class VersionCleanupAgent
{
	private const CHUNK_SIZE = 100;
	private const WATCHDOG_SECONDS = 10.0;
	private const LINK_AGE_SECONDS = 86400;
	private const LINK_CURSOR_OPTION = 'version_cleanup_link_cursor';
	// Zero wait on the content lock: background cleanup has nothing to wait for and must not hold up a
	// user write for a single second. A document busy right now is simply left to the next tick.
	private const LOCK_TIMEOUT_SECONDS = 0;
	private const RESCHEDULE_EXPRESSION = 'Bitrix\Note\Infrastructure\Agent\Version\VersionCleanupAgent::run();';

	/**
	 * Deletes content-version snapshots (b_note_document_version) older than the
	 * configured TTL. b_note_event / b_note_event_author are never touched here — they
	 * outlive the version snapshot by design; a content_changed event whose
	 * VERSION_ID now points at a deleted row simply becomes a non-interactive timeline node.
	 *
	 * After each batch's version rows are gone, sweep the documents touched by that
	 * batch for files that are now unreachable (no version and no current content references
	 * them) and delete them (link + CFile) post-commit — mirrors RecycleBinCleanupAgent's
	 * transaction-then-post-commit-CFile pattern, except version deletion here is already a
	 * single autocommitted statement, so "post-commit" simply means "after deleteByIds returns".
	 *
	 * A second pass then walks the file links themselves, document by document, so a file nothing
	 * references any more is collected even though no version of its document has expired — the case
	 * the version-driven sweep alone can never reach.
	 *
	 * @param float $budgetSeconds How long the version pass may work; a test squeezes it, the agent does not.
	 * @param float $linkBudgetSeconds The same for the link pass. It is a budget of its own, not what the
	 *                                 version pass left over: with a permanent version backlog the shared
	 *                                 one would never let the link pass run at all.
	 * @param DocumentUpdateRepository|null $updateRepository Collaborators the scheduler never passes: the
	 * @param FileReachabilityService|null $reachabilityService agent is a static entry point (registered as
	 * @param DocumentLockService|null $lockService a call expression), so a test that needs to make one of
	 *                                 them fail, race or refuse the lock has no other seam to reach them by.
	 * @return string The reschedule expression (the agent always stays registered).
	 */
	public static function run(
		float $budgetSeconds = self::WATCHDOG_SECONDS,
		float $linkBudgetSeconds = self::WATCHDOG_SECONDS,
		?DocumentUpdateRepository $updateRepository = null,
		?FileReachabilityService $reachabilityService = null,
		?DocumentLockService $lockService = null,
	): string
	{
		$ttl = Configuration::getVersionTtl();
		if ($ttl < 0)
		{
			// Disabled at runtime (negative TTL). Stay registered and just skip this tick —
			// an admin re-enabling TTL later must resume cleanup without a module reinstall
			// (matches NotificationDrainAgent's kill-switch-inside-the-tick lifecycle).
			return self::RESCHEDULE_EXPRESSION;
		}

		$cutoff = DateTime::createFromTimestamp(time() - $ttl * 86400);
		$linkedBefore = DateTime::createFromTimestamp(time() - self::LINK_AGE_SECONDS);
		$repository = new DocumentVersionRepository();
		$linkRepository = new DocumentFileLinkRepository();
		$documentRepository = new DocumentRepository();
		$updateRepository ??= new DocumentUpdateRepository();
		$reachabilityService ??= new FileReachabilityService();
		$lockService ??= new DocumentLockService();
		$fileCleanupService = new DocumentFileCleanupService();

		$deadline = microtime(true) + $budgetSeconds;
		$totalDeleted = 0;

		while (true)
		{
			$expiredRows = $repository->listExpiredAt($cutoff, self::CHUNK_SIZE);
			if (empty($expiredRows))
			{
				break;
			}

			$versionIds = array_map(static fn(array $row): int => $row['id'], $expiredRows);
			$affectedDocumentIds = array_values(array_unique(
				array_map(static fn(array $row): int => $row['documentId'], $expiredRows),
			));

			try
			{
				$repository->deleteByIds($versionIds);
			}
			catch (\Throwable $e)
			{
				self::logError('VersionCleanupAgent: ' . $e->getMessage());

				return self::RESCHEDULE_EXPRESSION;
			}

			$totalDeleted += count($versionIds);

			self::sweepUnreachableFiles(
				$affectedDocumentIds,
				$documentRepository,
				$updateRepository,
				$reachabilityService,
				$fileCleanupService,
				$lockService,
				$linkedBefore,
			);

			if (microtime(true) > $deadline)
			{
				break;
			}
		}

		if ($totalDeleted > 0)
		{
			\CEventLog::Add([
				'SEVERITY' => 'INFO',
				'AUDIT_TYPE_ID' => 'NOTE_VERSION_CLEANUP',
				'MODULE_ID' => 'note',
				'DESCRIPTION' => 'Deleted ' . $totalDeleted . ' expired document versions (ttl=' . $ttl . ' days)',
			]);
		}

		self::sweepLinkedDocuments(
			$linkBudgetSeconds,
			$linkRepository,
			$documentRepository,
			$updateRepository,
			$reachabilityService,
			$fileCleanupService,
			$lockService,
			$linkedBefore,
		);

		return self::RESCHEDULE_EXPRESSION;
	}

	/**
	 * Walks every document that owns file links, a chunk at a time, from the cursor the previous tick
	 * left behind; past the last one the cursor goes back to the start and the round begins again.
	 * The budget is checked after a chunk, never before, so even a tick whose version pass ran to its
	 * own limit still gets one chunk of links done.
	 *
	 * The cursor moves over what the sweep actually looked at, never over the chunk as such: a batch its
	 * guard could not clear leaves the cursor where it was, so the next tick retries those documents
	 * instead of making them wait for a whole round because of a momentary database error.
	 */
	private static function sweepLinkedDocuments(
		float $budgetSeconds,
		DocumentFileLinkRepository $linkRepository,
		DocumentRepository $documentRepository,
		DocumentUpdateRepository $updateRepository,
		FileReachabilityService $reachabilityService,
		DocumentFileCleanupService $fileCleanupService,
		DocumentLockService $lockService,
		DateTime $linkedBefore,
	): void
	{
		$cursor = (int)Option::get('note', self::LINK_CURSOR_OPTION, '0');
		$deadline = microtime(true) + $budgetSeconds;

		while (true)
		{
			try
			{
				$documentIds = $linkRepository->listDocumentIdsAfter($cursor, self::CHUNK_SIZE);
			}
			catch (\Throwable $e)
			{
				self::logError('VersionCleanupAgent link sweep: ' . $e->getMessage());

				return;
			}

			if (empty($documentIds))
			{
				Option::set('note', self::LINK_CURSOR_OPTION, '0');

				return;
			}

			$processedIds = self::sweepUnreachableFiles(
				$documentIds,
				$documentRepository,
				$updateRepository,
				$reachabilityService,
				$fileCleanupService,
				$lockService,
				$linkedBefore,
			);

			if (empty($processedIds))
			{
				return;
			}

			$cursor = (int)end($processedIds);
			Option::set('note', self::LINK_CURSOR_OPTION, (string)$cursor);

			if (microtime(true) > $deadline)
			{
				return;
			}
		}
	}

	/**
	 * Reachability is computed from Document.MARKDOWN plus the surviving version snapshots, and for a
	 * document with undrained patches MARKDOWN is behind the live text: a file uploaded minutes ago
	 * carries its token in the journal only, so it would be swept as unreachable while the editor is
	 * showing it. The patch log is therefore checked first, for the whole batch in one query, and such
	 * documents are left alone - the same guard OrphanFileCleanupAgent applies, for the same reason.
	 *
	 * The journal is drained by the client (compaction with a snapshot, overwrite, state save), so a
	 * document nobody comes back to keeps its patches indefinitely and is never swept here. Its files
	 * are collected by FileController::unlinkBatchAction instead - the path where the client, the only
	 * party that knows the live text, names the file it has just removed.
	 *
	 * A collaborative document with no materialization cursor is left alone for the same reason from
	 * the other side: its MARKDOWN was written before the cursor existed, by a client nothing records,
	 * and the editor of 26.0.0 wrote attachments into it as nothing at all. Reachability computed over
	 * such a text collected the files of every document untouched since - so the text is believed only
	 * once an edit has materialized it under the contract (DocumentRepository::materializeProjection).
	 *
	 * @param int[] $documentIds
	 * @param DateTime $linkedBefore Links younger than this are left alone: a file uploaded through
	 *                               REST and not yet written into the text is unreachable by
	 *                               definition, and only the age of its link says it is abandoned.
	 * @return int[] The documents the sweep actually looked at, in the order it walked them - what the
	 *               caller's cursor may move over. Empty means the batch guard failed and not one of
	 *               them was examined.
	 */
	private static function sweepUnreachableFiles(
		array $documentIds,
		DocumentRepository $documentRepository,
		DocumentUpdateRepository $updateRepository,
		FileReachabilityService $reachabilityService,
		DocumentFileCleanupService $fileCleanupService,
		DocumentLockService $lockService,
		DateTime $linkedBefore,
	): array
	{
		try
		{
			$pendingMap = array_fill_keys($updateRepository->filterDocumentIdsWithPatches($documentIds), true);
			$unmaterializedMap = array_fill_keys(
				$documentRepository->filterIdsWithoutMaterializedProjection($documentIds),
				true,
			);
		}
		catch (\Throwable $e)
		{
			// Without the guards the sweep is not safe to run: skip it for this batch rather than
			// delete files of a document whose live text could not be ruled out.
			self::logError('VersionCleanupAgent file sweep (guard lookup): ' . $e->getMessage());

			return [];
		}

		$processedIds = [];
		foreach ($documentIds as $documentId)
		{
			$processedIds[] = $documentId;
			if (isset($pendingMap[$documentId]) || isset($unmaterializedMap[$documentId]))
			{
				continue;
			}

			// Everything from here to the deleted link row happens under the document's content lock -
			// the same one OverwriteDocumentContentCommand, materialization and compaction take for
			// their write. Without it reachability is answered about a text that may already be
			// changing: a REST overwrite could put an old token back between the answer and
			// CFile::Delete(), leaving the saved document pointing at a file gone for good, and the
			// patch-log guard is blind to it because REST writes content directly and creates no
			// patches. A document somebody is writing to right now is left to the next tick.
			if (!$lockService->acquireLock($documentId, self::LOCK_TIMEOUT_SECONDS))
			{
				continue;
			}

			try
			{
				// Candidates are the links old enough to be abandoned; whether the text still reaches
				// them is asked once, below. Asking here too would read MARKDOWN and every version
				// snapshot of the document twice.
				$candidateFileIds = $reachabilityService->listLinkedFileIds($documentId, $linkedBefore);
				if (empty($candidateFileIds))
				{
					continue;
				}

				$confirmedFileIds = $reachabilityService->filterStillUnreachable($documentId, $candidateFileIds);
				if (!empty($confirmedFileIds))
				{
					$fileCleanupService->cleanupUnreachableFiles($documentId, $confirmedFileIds);
				}
			}
			catch (\Throwable $e)
			{
				// Best-effort: a failed sweep for one document must not block version TTL
				// cleanup for the rest of the batch/run.
				self::logError('VersionCleanupAgent file sweep (document ' . $documentId . '): ' . $e->getMessage());
			}
			finally
			{
				$lockService->releaseLock($documentId);
			}
		}

		return $processedIds;
	}

	private static function logError(string $message): void
	{
		\CEventLog::Add([
			'SEVERITY' => \CEventLog::SEVERITY_ERROR,
			'AUDIT_TYPE_ID' => 'NOTE_VERSION_CLEANUP_ERROR',
			'MODULE_ID' => 'note',
			'DESCRIPTION' => $message,
		]);
	}
}
