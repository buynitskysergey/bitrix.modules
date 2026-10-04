<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Agent\Version;

use Bitrix\Main\Type\DateTime;
use Bitrix\Note\Internal\Configuration;
use Bitrix\Note\Internal\Repository\DocumentVersionRepository;
use Bitrix\Note\Internal\Service\DocumentFileCleanupService;
use Bitrix\Note\Internal\Service\File\FileReachabilityService;

final class VersionCleanupAgent
{
	private const CHUNK_SIZE = 100;
	private const WATCHDOG_SECONDS = 10.0;
	private const RESCHEDULE_EXPRESSION = 'Bitrix\Note\Infrastructure\Agent\Version\VersionCleanupAgent::run();';

	/**
	 * Deletes content-version snapshots (b_note_document_version) older than the
	 * configured TTL. b_note_event / b_note_event_author are never touched here — they
	 * outlive the version snapshot by design (see [P1.T4]); a content_changed event whose
	 * VERSION_ID now points at a deleted row simply becomes a non-interactive timeline node.
	 *
	 * [P4.T3] After each batch's version rows are gone, sweep the documents touched by that
	 * batch for files that are now unreachable (no version and no current content references
	 * them) and delete them (link + CFile) post-commit — mirrors RecycleBinCleanupAgent's
	 * transaction-then-post-commit-CFile pattern, except version deletion here is already a
	 * single autocommitted statement, so "post-commit" simply means "after deleteByIds returns".
	 *
	 * @return string The reschedule expression (the agent always stays registered).
	 */
	public static function run(): string
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
		$repository = new DocumentVersionRepository();
		$reachabilityService = new FileReachabilityService();
		$fileCleanupService = new DocumentFileCleanupService();

		$startTime = microtime(true);
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

			self::sweepUnreachableFiles($affectedDocumentIds, $reachabilityService, $fileCleanupService);

			if ((microtime(true) - $startTime) > self::WATCHDOG_SECONDS)
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

		return self::RESCHEDULE_EXPRESSION;
	}

	/**
	 * @param int[] $documentIds
	 */
	private static function sweepUnreachableFiles(
		array $documentIds,
		FileReachabilityService $reachabilityService,
		DocumentFileCleanupService $fileCleanupService,
	): void
	{
		foreach ($documentIds as $documentId)
		{
			try
			{
				$unreachableFileIds = $reachabilityService->getUnreachableFileIds($documentId);
				if (!empty($unreachableFileIds))
				{
					$fileCleanupService->cleanupUnreachableFiles($documentId, $unreachableFileIds);
				}
			}
			catch (\Throwable $e)
			{
				// Best-effort: a failed sweep for one document must not block version TTL
				// cleanup for the rest of the batch/run.
				self::logError('VersionCleanupAgent file sweep (document ' . $documentId . '): ' . $e->getMessage());
			}
		}
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
