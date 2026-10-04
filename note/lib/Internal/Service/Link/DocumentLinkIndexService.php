<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Link;

use Bitrix\Main\Application;
use Bitrix\Note\Infrastructure\Agent\Link\DocumentLinkRepairScheduler;
use Bitrix\Note\Internal\Model\DocumentTable;
use Bitrix\Note\Internal\Repository\DocumentLinkRepository;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Service\Collaboration\DocumentLockService;
use Bitrix\Note\Internal\Service\Collaboration\PushNotificationService;

/**
 * [P2.T2 / ALG-01] The only writer of b_note_document_link. One source's whole target set is
 * rewritten from its current content; every write path — compaction, REST overwrite, import,
 * hard delete — arrives here and nowhere else.
 *
 * The rebuild runs in a transaction of its own, AFTER the one that saved the content, so "content
 * plus index" is deliberately not atomic: a torn index is repaired by the next save or by
 * {@see \Bitrix\Note\Infrastructure\Agent\Link\DocumentLinkRepairAgent}, whereas holding the
 * content save hostage to the index would trade a stale counter for a lost edit.
 *
 * {@see rebuild()} therefore never throws: the write commands that call it must not fail because
 * of the index.
 */
final class DocumentLinkIndexService
{
	// [EVENT-01] Addressed to the channel of the TARGET whose incoming set changed. No payload
	// beyond the id: the counter is personal (it is filtered by the reader's own permissions) while
	// the document channel is shared by everyone watching, so the client re-reads its own value.
	public const COMMAND_BACKLINKS_CHANGED = 'documentBacklinksChanged';

	// [B2] Rebuilds of one source are serialized under this lock scope. Two saves of the same document
	// could otherwise apply their target sets in reverse of the order their content was written, both
	// looking successful, with no torn state for repair to notice. The wait is short: real contention on
	// one document is rare, and a rebuild that cannot get the lock in time is deferred to the repair
	// agent rather than racing.
	private const LOCK_SCOPE = 'link_index';
	private const LOCK_TIMEOUT_SECONDS = 5;

	public function __construct(
		private readonly DocumentRepository $documentRepository = new DocumentRepository(),
		private readonly DocumentLinkRepository $linkRepository = new DocumentLinkRepository(),
		private readonly DocumentLinkExtractor $extractor = new DocumentLinkExtractor(),
		private readonly PushNotificationService $pushService = new PushNotificationService(),
		private readonly DocumentLinkRepairScheduler $repairScheduler = new DocumentLinkRepairScheduler(),
		private readonly DocumentLockService $lockService = new DocumentLockService(),
	)
	{
	}

	/**
	 * Brings the source's target set in step with its content. A failure is logged and handed to the
	 * repair agent instead of reaching the caller.
	 */
	public function rebuild(int $sourceId): void
	{
		$this->guarded($sourceId, fn() => $this->rebuildOrFail($sourceId));
	}

	/**
	 * The rebuild with its failure left visible. Only {@see DocumentLinkRepairAgent} calls this: the
	 * agent owns the retry budget and cannot count attempts it never learns about.
	 *
	 * @throws \Throwable
	 */
	public function rebuildOrFail(int $sourceId): void
	{
		if ($sourceId <= 0)
		{
			return;
		}

		// [B2] Read the content and apply the new set under the per-source lock, so a concurrent save
		// cannot slip its own rebuild in between and leave the index reflecting the older content.
		if (!$this->lockService->acquireLock($sourceId, self::LOCK_TIMEOUT_SECONDS, self::LOCK_SCOPE))
		{
			throw new \RuntimeException(
				'DocumentLinkIndexService: could not acquire link_index lock for source ' . $sourceId,
			);
		}

		try
		{
			$document = $this->documentRepository->getMetaById(
				$sourceId,
				['ID', 'CONTENT_FORMAT', 'MARKDOWN'],
				useCache: false,
			);
			if ($document === null)
			{
				return;
			}

			// Legacy format: MARKDOWN holds an encoded TipTap tree, not markdown. Read-only, so its link
			// set can never change — parsing it would only invent targets out of json punctuation.
			if ($document->getContentFormat() === DocumentTable::CONTENT_FORMAT_JSON)
			{
				return;
			}

			$this->apply($sourceId, $this->extractor->extract($document->getMarkdownRaw(), $sourceId));
		}
		finally
		{
			$this->lockService->releaseLock($sourceId, self::LOCK_SCOPE);
		}
	}

	/**
	 * [C2-perf] Batch rebuild for the one-time backfill. Reads the whole chunk's content and current
	 * target sets in two queries instead of two per document, skips the sources already in step with
	 * their content without taking a lock or a transaction, and reindexes only the ones that differ —
	 * each of those through the ordinary per-source locked rebuild, so the serialization guarantee is
	 * unchanged. The caller passes only non-json documents; a document that flipped format or vanished
	 * mid-scan is skipped defensively.
	 *
	 * @param int[] $sourceIds
	 * @return array{rebuilt: int, failedIds: int[]}
	 */
	public function rebuildBatch(array $sourceIds): array
	{
		$ids = array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $sourceIds),
			static fn(int $id): bool => $id > 0,
		)));
		if (empty($ids))
		{
			return ['rebuilt' => 0, 'failedIds' => []];
		}

		$documents = $this->documentRepository->getByIds($ids, ['ID', 'CONTENT_FORMAT', 'MARKDOWN']);
		$oldTargetsMap = $this->linkRepository->getTargetIdsBySources($ids);

		$rebuilt = 0;
		$failedIds = [];
		foreach ($ids as $sourceId)
		{
			$row = $documents[$sourceId] ?? null;
			if ($row === null || (string)($row['CONTENT_FORMAT'] ?? '') === DocumentTable::CONTENT_FORMAT_JSON)
			{
				continue;
			}

			$newTargets = $this->extractor->extract((string)($row['MARKDOWN'] ?? ''), $sourceId);
			if ($this->setEquals($oldTargetsMap[$sourceId] ?? [], $newTargets))
			{
				// Already indexed correctly (a replay, or a document whose links never changed): no lock,
				// no transaction, no signal — the common case of a re-run.
				$rebuilt++;

				continue;
			}

			try
			{
				// Re-reads fresh content under the lock: the batched read above is only the fast-path
				// difference test, never what gets written.
				$this->rebuildOrFail($sourceId);
				$rebuilt++;
			}
			catch (\Throwable $e)
			{
				$failedIds[] = $sourceId;
				$this->logError('DocumentLinkIndexService: backfill batch, document ' . $sourceId . ': ' . $e->getMessage());
			}
		}

		return ['rebuilt' => $rebuilt, 'failedIds' => $failedIds];
	}

	/**
	 * @param int[] $newTargets
	 * @throws \Throwable
	 */
	private function apply(int $sourceId, array $newTargets): void
	{
		if ($sourceId <= 0)
		{
			return;
		}

		$oldTargets = $this->linkRepository->getTargetIds($sourceId);
		if ($this->setEquals($oldTargets, $newTargets))
		{
			// The common case by far — a save that touched no link. No transaction, no signal.
			return;
		}

		$connection = Application::getConnection();
		$connection->startTransaction();
		try
		{
			$this->linkRepository->replaceTargets($sourceId, $newTargets);
			$connection->commitTransaction();
		}
		catch (\Throwable $e)
		{
			try
			{
				$connection->rollbackTransaction();
			}
			catch (\Throwable)
			{
				// Hard delete calls the rebuild from inside its own transaction, and a nested
				// rollback complains after undoing the savepoint. The undo is what matters; letting
				// the complaint through would replace the real cause in the log.
			}

			throw $e;
		}

		// Both directions of the change: a target that LOST its link has to hear about the smaller
		// count exactly as the one that gained it hears about the bigger one.
		$this->notifyTargets(array_values(array_unique(array_merge($oldTargets, $newTargets))));
	}

	/**
	 * [D1] The source's own text did not change, but something a backlink popover reads off the source
	 * through a join did — its title (rename) or its visibility (archive / trash / restore). Nudge every
	 * document it points at to re-read, so a target never shows a stale name or a ghost source. No index
	 * write: the target set is unchanged.
	 */
	public function notifySourceMetadataChanged(int $sourceId): void
	{
		if ($sourceId <= 0)
		{
			return;
		}

		$this->notifyTargets($this->linkRepository->getTargetIds($sourceId));
	}

	/**
	 * Batch form of {@see notifySourceMetadataChanged()} for a whole subtree/selection: one query for
	 * the union of targets, one deduplicated fan-out.
	 *
	 * @param int[] $sourceIds
	 */
	public function notifySourceMetadataChangedForMany(array $sourceIds): void
	{
		$this->notifyTargets($this->linkRepository->collectTargetIdsBySources($sourceIds));
	}

	/**
	 * Fan-out to an explicit target set the caller already resolved. Hard delete collects the affected
	 * targets before the source rows go, since after the delete there is nothing left to read them from.
	 *
	 * @param int[] $targetIds
	 */
	public function notifyBacklinksChanged(array $targetIds): void
	{
		$this->notifyTargets(array_values(array_unique(array_map(
			static fn($id): int => (int)$id,
			$targetIds,
		))));
	}

	/**
	 * @param int[] $targetIds
	 */
	private function notifyTargets(array $targetIds): void
	{
		if (empty($targetIds))
		{
			return;
		}

		if (count($targetIds) > PushNotificationService::REALTIME_BATCH_THRESHOLD)
		{
			// One save moving this many links is an import or a mass paste, not editing. There is no
			// aggregate channel to degrade onto here — every target owns its own — so the fan-out is
			// dropped rather than paid for, and the affected documents pick the count up on their
			// next read. Logged so a portal doing this routinely is visible.
			$this->logError(
				'DocumentLinkIndexService: backlink fan-out skipped, ' . count($targetIds) . ' targets',
			);

			return;
		}

		$this->pushService->dispatchAfterCommit(function () use ($targetIds): void {
			foreach ($targetIds as $targetId)
			{
				$this->pushService->sendToDocument(
					$targetId,
					self::COMMAND_BACKLINKS_CHANGED,
					['documentId' => $targetId],
				);
			}
		});
	}

	private function guarded(int $sourceId, callable $pass): void
	{
		if ($sourceId <= 0)
		{
			return;
		}

		try
		{
			$pass();
		}
		catch (\Throwable $e)
		{
			$this->logError('DocumentLinkIndexService: ' . $e->getMessage());
			$this->repairScheduler->schedule($sourceId);
		}
	}

	/**
	 * @param int[] $left
	 * @param int[] $right
	 */
	private function setEquals(array $left, array $right): bool
	{
		if (count($left) !== count($right))
		{
			return false;
		}

		sort($left);
		sort($right);

		return $left === $right;
	}

	private function logError(string $message): void
	{
		\CEventLog::Add([
			'SEVERITY' => \CEventLog::SEVERITY_ERROR,
			'AUDIT_TYPE_ID' => 'NOTE_DOCUMENT_LINK_INDEX_ERROR',
			'MODULE_ID' => 'note',
			'DESCRIPTION' => $message,
		]);
	}
}
