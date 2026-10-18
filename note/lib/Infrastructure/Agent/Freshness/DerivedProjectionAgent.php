<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Agent\Freshness;

use Bitrix\Main\Application;
use Bitrix\Note\Internal\Model\DocumentTable;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Service\File\DiskAttachmentSyncService;
use Bitrix\Note\Internal\Service\Link\DocumentLinkIndexService;
use Bitrix\Note\Internal\Service\Search\SearchIndexService;

/**
 * The one freshness agent for every derived projection: it drains the
 * per-document dirty flag IS_DERIVED_STALE='Y' and, in a single pass, recomputes the full-text search
 * index, the outgoing-link index and the Disk attachments from the current MARKDOWN. Materialization and
 * compaction raise the flag but no longer index inline (see CompactDocumentCommand); create/update
 * /import and overwrite rebuild every projection inline, Disk attachments included, and keep the flag
 * 'N', so they are never picked up here.
 *
 * The flag is the queue — there is no persistent cursor. Processing is clear-then-work: a chunk's flags
 * are cleared BEFORE its documents are reindexed, so a write that lands mid-reindex re-raises 'Y' and is
 * caught on the next tick by the current text (safer than comparing CONTENT_UPDATED_AT, whose second
 * precision could not tell two writes in the same second apart).
 *
 * Delivery guarantee. Every failure the agent can observe puts the document back into the queue:
 * reindexDerived re-raises the flag when a projection throws, and the watchdog hands the untouched tail
 * of a chunk back before returning. What clear-then-work does NOT survive is the process dying outright
 * (max_execution_time, OOM, a killed worker) between the chunk being claimed and its documents being
 * reindexed: those documents leave the queue with a stale index, and only a later write brings them
 * back. The loss window is one chunk. That trade is deliberate — clearing after the reindex instead
 * would silently drop every write that landed while the reindex was running, which is the more common
 * event by far.
 */
final class DerivedProjectionAgent
{
	private const LOCK_NAME = 'note_derived_projection';
	private const CLAIM_LOCK_TIMEOUT_SECONDS = 1;
	private const WATCHDOG_SECONDS = 10.0;
	private const CHUNK_SIZE = 50;
	private const RESCHEDULE_EXPRESSION = 'Bitrix\Note\Infrastructure\Agent\Freshness\DerivedProjectionAgent::run();';

	public static function run(): string
	{
		self::drain();

		return self::RESCHEDULE_EXPRESSION;
	}

	private static function drain(): void
	{
		$repository = new DocumentRepository();
		$lastId = 0;
		$start = microtime(true);

		while (!self::deadlineReached($start))
		{
			$ids = self::claimChunk($repository, $lastId);
			if (empty($ids))
			{
				break;
			}

			$lastId = max($ids);

			foreach ($ids as $position => $id)
			{
				// The deadline is checked per document, not per chunk: a single link rebuild can wait
				// seconds on a lock, so a chunk of 50 could hold the php worker far past the watchdog.
				// The tail is already claimed (its flags are down), so it has to be handed back explicitly.
				if (self::deadlineReached($start))
				{
					self::releaseClaim($repository, array_slice($ids, $position));

					return;
				}

				self::reindexDerived($id, $repository);
			}
		}
	}

	/**
	 * Takes the next chunk out of the queue: read the ids, then lower their flags, both under the lock —
	 * so two overlapping ticks claim disjoint sets and neither reindexes the other's documents.
	 *
	 * The lock covers the claim only, never the reindex, for two reasons. On production MySQL (5.6) a
	 * session holds at most one named lock — GET_LOCK silently releases the previous one — and the link
	 * rebuild takes a named lock of its own per document, which would drop this one on the very first
	 * document of the tick. And a rebuild can wait seconds on that lock, so a tick-wide lock would gate
	 * the whole portal's queue behind one slow document.
	 *
	 * @return int[]
	 */
	private static function claimChunk(DocumentRepository $repository, int $afterId): array
	{
		$connection = Application::getConnection();
		if (!$connection->lock(self::LOCK_NAME, self::CLAIM_LOCK_TIMEOUT_SECONDS))
		{
			// Another tick is claiming right now — leave the queue to it.
			return [];
		}

		try
		{
			$ids = $repository->listStaleDerived($afterId, self::CHUNK_SIZE);
			if (!empty($ids))
			{
				$repository->clearDerivedStale($ids);
			}

			return $ids;
		}
		finally
		{
			$connection->unlock(self::LOCK_NAME);
		}
	}

	/**
	 * @param int[] $ids
	 */
	private static function releaseClaim(DocumentRepository $repository, array $ids): void
	{
		try
		{
			$repository->markDerivedStale($ids);
		}
		catch (\Throwable $e)
		{
			self::logError('returning the unprocessed tail to the queue failed: ' . $e->getMessage());
		}
	}

	/**
	 * Recompute every derived projection from the current MARKDOWN. Failures are absorbed (never
	 * rethrown): a throw would abort the whole tick and strand the rest of the chunk.
	 *
	 * A failure does put the document back into the queue, though. The flag was already cleared, so
	 * without this the document would leave the queue with a stale index and nothing would ever pick it
	 * up again — the next tick does not see it, and only a fresh edit would raise the flag anew.
	 * Re-raising cannot lose a concurrent write: that write raises the very same value.
	 */
	private static function reindexDerived(int $id, DocumentRepository $repository): void
	{
		$failed = false;
		// Whether the text is markdown at all. A legacy json document keeps an encoded TipTap tree in
		// MARKDOWN, where the token parser finds nothing — the sync would read that as "the text mentions
		// no files" and detach every attachment the document has. update() keeps such a document out of
		// this queue, so this only guards the ways in that do not go through it (setMarkdown, a hand-raised
		// flag), and it stays down until the format is actually known.
		$attachmentsDerivable = false;

		try
		{
			// One read serves the archived check, the search body and the content format: MARKDOWN is a
			// MEDIUMTEXT and indexDocument() would otherwise fetch it a second time. Uncached, because the
			// archiving may have happened seconds ago, while this tick was already running.
			$document = $repository->getMetaById(
				$id,
				['ID', 'IS_ARCHIVED', 'TITLE', 'MARKDOWN', 'CONTENT_FORMAT'],
				useCache: false,
			);
			if ($document === null)
			{
				// Deleted since the claim; the delete cleans every projection itself.
				return;
			}

			$attachmentsDerivable = (string)$document->getContentFormat() !== DocumentTable::CONTENT_FORMAT_JSON;

			// The flag says the text changed; it says nothing about what happened to the document
			// afterwards. Archiving removes the document from the search index and leaves the flag
			// standing, so an unconditional reindex here would quietly put an archived document back into
			// everyone's search results — the edit and the archiving only need to fall within the same
			// tick. The current state decides: archived means out of the index, not into it.
			// The recycle bin needs no branch — SearchIndexService refuses to index a trashed document.
			if ($document->getIsArchived())
			{
				(new SearchIndexService())->deindexDocument($id);
			}
			else
			{
				(new SearchIndexService())->indexDocument(
					$id,
					$document->getMarkdownRaw(),
					(string)$document->getTitle(),
				);
			}
		}
		catch (\Throwable $e)
		{
			$failed = true;
			self::logError('search reindex failed for document ' . $id . ': ' . $e->getMessage());
		}

		try
		{
			// Re-reads the content itself, under its own per-source lock: what it writes must be the text
			// as of the moment it holds that lock, not the snapshot read above.
			(new DocumentLinkIndexService())->rebuild($id);
		}
		catch (\Throwable $e)
		{
			$failed = true;
			self::logError('link rebuild failed for document ' . $id . ': ' . $e->getMessage());
		}

		if ($attachmentsDerivable)
		{
			try
			{
				// Disk attachments are a projection of the text too, and this pass keeps the projection from
				// outliving the text: an attachment nothing mentions any more is dropped here.
				//
				// Dropped, but never made: the actor is 0 on purpose. Attaching is an act on the file, so Disk
				// checks somebody's rights, and the agent has no user of its own to offer. The obvious stand-in
				// — whoever the document says changed it last — is the wrong one, because materializing text
				// does not move UPDATED_BY: the text can be written by one person while the field still names
				// another, and attaching on the named person's rights would let the writer spend rights that
				// are not theirs on a file they cannot read themselves. A picked file is attached the moment it
				// is picked, and the editing session settles the rest (see MaterializeDocumentCommand), both
				// with a real actor; a file that reached the text some other way stays unattached and the block
				// keeps saying so until a write with an actor picks it up.
				// Reads the stored text itself rather than reusing the snapshot above: that snapshot may be
				// seconds old by now, and detaching a file the text mentions again is worse than a tick spent
				// on a second read.
				(new DiskAttachmentSyncService())->sync($id, actorId: 0);
			}
			catch (\Throwable $e)
			{
				$failed = true;
				self::logError('disk attachment sync failed for document ' . $id . ': ' . $e->getMessage());
			}
		}

		if ($failed)
		{
			try
			{
				$repository->markDerivedStale([$id]);
			}
			catch (\Throwable $e)
			{
				self::logError('re-raising the stale flag failed for document ' . $id . ': ' . $e->getMessage());
			}
		}
	}

	private static function deadlineReached(float $start): bool
	{
		return (microtime(true) - $start) >= self::WATCHDOG_SECONDS;
	}

	private static function logError(string $message): void
	{
		\CEventLog::Add([
			'SEVERITY' => \CEventLog::SEVERITY_ERROR,
			'AUDIT_TYPE_ID' => 'NOTE_DERIVED_PROJECTION_ERROR',
			'MODULE_ID' => 'note',
			'DESCRIPTION' => $message,
		]);
	}
}
