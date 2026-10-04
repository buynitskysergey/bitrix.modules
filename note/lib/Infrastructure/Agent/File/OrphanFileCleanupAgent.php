<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Agent\File;

use Bitrix\Main\Config\Option;
use Bitrix\Note\Internal\Repository\DocumentFileLinkRepository;
use Bitrix\Note\Internal\Repository\DocumentUpdateRepository;
use Bitrix\Note\Internal\Service\DocumentFileCleanupService;
use Bitrix\Note\Internal\Service\File\FileReachabilityService;

/**
 * One-time (self-unregistering) backfill sweep for files orphaned before the document history
 * feature existed. Prior to versioning, a file removed from a document's content left its
 * b_note_document_file link behind with no version snapshot to keep it reachable — so it is
 * unreachable the moment this feature ships. The recurring VersionCleanupAgent only sweeps a
 * document once one of ITS versions expires (90 days), and a document never edited after the
 * feature never gets a version to expire, so its orphan would linger forever. This agent closes
 * that gap in a single pass, then removes itself.
 *
 * Reuses the exact reachability + deletion pair the recurring sweep and FileController already use
 * (getUnreachableFileIds -> cleanupUnreachableFiles); no new deletion path is introduced.
 *
 * Race safety: the only window where a linked-but-"unreachable" file is actually in use is a fresh
 * upload whose token lives in the live YJS patch log but is not yet flushed to Document.MARKDOWN or
 * any version. Such a document has undrained patches, so we skip any document with a non-empty
 * patch log (DocumentUpdateRepository::hasAnyByDocumentId) — the same guard
 * OverwriteDocumentContentCommand uses. With an empty patch log, Document.MARKDOWN is authoritative
 * (CompactDocumentCommand flushes it on every compact), so reachability is accurate. A skipped
 * document is simply left to the recurring version-TTL sweep — an accepted no-false-deletion leak.
 */
final class OrphanFileCleanupAgent
{
	private const CHUNK_SIZE = 100;
	private const WATCHDOG_SECONDS = 10.0;
	private const CURSOR_OPTION = 'orphan_file_cleanup_cursor';
	private const RESCHEDULE_EXPRESSION = 'Bitrix\Note\Infrastructure\Agent\File\OrphanFileCleanupAgent::run();';

	/**
	 * @return string RESCHEDULE_EXPRESSION to run again next tick, or '' to self-unregister once
	 *                every document that owns files has been swept.
	 */
	public static function run(): string
	{
		$linkRepository = new DocumentFileLinkRepository();
		$updateRepository = new DocumentUpdateRepository();
		$reachabilityService = new FileReachabilityService();
		$fileCleanupService = new DocumentFileCleanupService();

		$cursor = (int)Option::get('note', self::CURSOR_OPTION, '0');
		$startTime = microtime(true);
		$totalDeleted = 0;

		while (true)
		{
			$documentIds = $linkRepository->listDocumentIdsAfter($cursor, self::CHUNK_SIZE);
			if (empty($documentIds))
			{
				// Nothing left past the cursor: the pass is complete. Drop the cursor and
				// self-unregister by returning an empty reschedule expression.
				Option::delete('note', ['name' => self::CURSOR_OPTION]);

				if ($totalDeleted > 0)
				{
					self::logInfo('OrphanFileCleanupAgent finished: deleted ' . $totalDeleted . ' orphaned files.');
				}

				return '';
			}

			foreach ($documentIds as $documentId)
			{
				$totalDeleted += self::sweepDocument(
					$documentId,
					$updateRepository,
					$reachabilityService,
					$fileCleanupService,
				);

				// Advance and persist per document so a mid-page watchdog break resumes exactly
				// after the last fully-processed document — never re-scanning or skipping one.
				$cursor = $documentId;
				Option::set('note', self::CURSOR_OPTION, (string)$cursor);

				if ((microtime(true) - $startTime) > self::WATCHDOG_SECONDS)
				{
					return self::RESCHEDULE_EXPRESSION;
				}
			}
		}
	}

	/**
	 * @return int Number of files physically deleted for this document.
	 */
	private static function sweepDocument(
		int $documentId,
		DocumentUpdateRepository $updateRepository,
		FileReachabilityService $reachabilityService,
		DocumentFileCleanupService $fileCleanupService,
	): int
	{
		try
		{
			// Skip actively-edited documents: undrained patches mean Document.MARKDOWN may lag the
			// live content, so a freshly uploaded token could look unreachable while still in use.
			if ($updateRepository->hasAnyByDocumentId($documentId))
			{
				return 0;
			}

			$unreachableFileIds = $reachabilityService->getUnreachableFileIds($documentId);
			if (empty($unreachableFileIds))
			{
				return 0;
			}

			$result = $fileCleanupService->cleanupUnreachableFiles($documentId, $unreachableFileIds);

			return count((array)($result->getData()['successFileIds'] ?? []));
		}
		catch (\Throwable $e)
		{
			// Best-effort: a failure on one document must not stall the rest of the pass.
			self::logError('OrphanFileCleanupAgent (document ' . $documentId . '): ' . $e->getMessage());

			return 0;
		}
	}

	private static function logInfo(string $message): void
	{
		\CEventLog::Add([
			'SEVERITY' => 'INFO',
			'AUDIT_TYPE_ID' => 'NOTE_ORPHAN_FILE_CLEANUP',
			'MODULE_ID' => 'note',
			'DESCRIPTION' => $message,
		]);
	}

	private static function logError(string $message): void
	{
		\CEventLog::Add([
			'SEVERITY' => \CEventLog::SEVERITY_ERROR,
			'AUDIT_TYPE_ID' => 'NOTE_ORPHAN_FILE_CLEANUP_ERROR',
			'MODULE_ID' => 'note',
			'DESCRIPTION' => $message,
		]);
	}
}
