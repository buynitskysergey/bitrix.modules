<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\File;

use Bitrix\Note\Internal\Configuration;
use Bitrix\Note\Internal\Integration\Disk\DiskGateway;
use Bitrix\Note\Internal\Repository\DocumentRepository;

/**
 * Brings a document's Disk attachments in line with what its markdown actually references.
 *
 * Attachments are a projection of the text, like the search and link indexes: the set of attachments
 * is derived from the stored markdown, whichever way that markdown was written — editor, REST or
 * import — which makes the operation idempotent, and makes it the answer for every path that has no
 * picker to hook onto (a token copied from another document, an imported file, a REST write).
 *
 * The projection is recomputed wherever the stored markdown moves:
 * {@see \Bitrix\Note\Public\Command\MaterializeDocumentCommand} — the frequent one, running while the
 * document is still being edited — the two commands that settle content,
 * {@see \Bitrix\Note\Public\Command\CompactDocumentCommand} and
 * {@see \Bitrix\Note\Public\Command\OverwriteDocumentContentCommand}, the create/update path
 * {@see \Bitrix\Note\Internal\Service\Document\DocumentService}, and the freshness agent
 * {@see \Bitrix\Note\Infrastructure\Agent\Freshness\DerivedProjectionAgent}, which catches a document
 * whose text changed without any of them.
 *
 * It is not, however, how a picked file gets attached in time. The projection follows the stored text,
 * and until the text is stored the file has no attachment, so every reader of the document sees the
 * block as unavailable. The picker therefore attaches what it just picked right away
 * ({@see \Bitrix\Note\Infrastructure\Controller\FileController::attachDiskObjectsAction}), on the
 * rights of the person who picked it; this sync is what makes that safe, because an attachment the
 * text never came to mention is dropped on the next pass.
 *
 * Only markdown may be handed in. A json (TipTap) payload keeps an encoded tree in MARKDOWN, where
 * the parser finds no tokens at all — running this on one would read as "the text mentions nothing"
 * and detach everything the document has. Callers check the format; there is no format check here,
 * because the frequent caller has the text on hand and reading the row again would undo the saving.
 *
 * Not hung on the content-settled event: that event is the module's public boundary for outside (RAG)
 * consumers, not an internal hook. A pure title change publishes it too and has no attachments to
 * reconcile.
 */
final class DiskAttachmentSyncService
{
	public function __construct(
		private readonly DocumentRepository $documentRepository = new DocumentRepository(),
		private readonly DiskGateway $diskGateway = new DiskGateway(),
	)
	{
	}

	/**
	 * The same reconciliation, but never at the cost of the write that asked for it.
	 *
	 * Every caller of this runs after the text is already stored: the markdown, the cursor and, for the
	 * two settling commands, the version are written and committed. A side effect that fails after that
	 * must not turn a finished write into an error — the caller would report a failure the client cannot
	 * act on (the projection did land, so resending it is refused by the forward-only guard), and for
	 * compaction and overwrite the content-settled event would be skipped, leaving outside consumers
	 * unaware of text that is already stored.
	 *
	 * A failure therefore only raises the dirty flag: the freshness agent reconciles Disk attachments on
	 * its own pass, and a stale mark is how a document gets into that pass. The cost is the agent
	 * rebuilding the search and link indexes of that document too, which is the cheaper mistake.
	 *
	 * That pass only detaches, though — it runs without an actor (see DerivedProjectionAgent) — so an
	 * attachment lost to a failure here waits for the next write that has one, which for an open document
	 * is the next pause in editing.
	 */
	public function syncBestEffort(
		int $documentId,
		int $actorId,
		?string $markdown = null,
		?string $actorMarkdown = null,
	): void
	{
		try
		{
			$this->sync($documentId, $actorId, $markdown, $actorMarkdown);
		}
		catch (\Throwable $error)
		{
			$this->reportFailure($documentId, $error);
		}
	}

	/**
	 * @param int $actorId User whose write this is, or 0 for a background one. Without an actor only
	 *                     detaching happens: attaching needs somebody whose rights on the file Disk can
	 *                     check, so it waits for the next write that has one.
	 * @param string|null $markdown The stored text, when the caller can vouch for it being the stored one —
	 *                     MARKDOWN is a MEDIUMTEXT and this runs on every materialization, so a caller
	 *                     holding the document's projection lock saves the read. NULL reads it.
	 * @param string|null $actorMarkdown The text this actor wrote, when it may no longer be the stored one.
	 *                     Attaching is then limited to what both texts name; see below.
	 */
	public function sync(
		int $documentId,
		int $actorId,
		?string $markdown = null,
		?string $actorMarkdown = null,
	): void
	{
		if ($documentId <= 0 || !$this->diskGateway->isAvailable())
		{
			return;
		}

		$markdown ??= $this->documentRepository->getRawMarkdown($documentId);
		if ($markdown === null)
		{
			return;
		}

		$desired = AssetTokenParser::extractDiskObjectIds($markdown);
		$existing = $this->diskGateway->listAttachments($documentId);

		// What this actor may have attached on their rights. The two texts differ when writes land at once
		// and finish their side effects in the other order — possible only for a writer that holds no
		// projection lock, which is create/update (see DocumentService); materialization, compaction and
		// overwrite hold that lock across their reconciliation, so for them the stored text is their own.
		//
		// The older write's reconciliation then runs over text it did not write, and it must only be able
		// to take away, never to give: giving would attach a file on the rights of somebody who never named
		// it, which is the same borrowing of rights the freshness agent is barred from. Detaching still
		// follows the stored text, so whichever order the two reconciliations finish in, the attachments
		// end up matching the text that is actually stored — the newer write attaches what it named itself.
		$attachable = $actorMarkdown === null
			? $desired
			: array_intersect($desired, AssetTokenParser::extractDiskObjectIds($actorMarkdown))
		;

		// A set, not a list to search: an import or a REST write can put thousands of tokens in one
		// document, and the resolve endpoint's ceiling does not apply to this path.
		$desiredMap = array_fill_keys($desired, true);

		$stale = [];
		foreach ($existing as $objectId => $attachedObjectIds)
		{
			if (!isset($desiredMap[$objectId]))
			{
				array_push($stale, ...$attachedObjectIds);

				continue;
			}

			// A file the text still mentions is kept attached exactly once. More than one attachment can
			// only come from two writes deciding at the same moment that nothing was attached yet — the
			// eager attach of a just-picked file and this sync, say — because Disk has no unique key to
			// refuse the second row. The extra rows answer the permission question identically, so the
			// oldest one stays and the rest go: nobody loses access, and the file stops being queued for
			// transcoding once per row.
			if (count($attachedObjectIds) > 1)
			{
				sort($attachedObjectIds);
				array_push($stale, ...array_slice($attachedObjectIds, 1));
			}
		}

		// Detaching first, attaching second. The document's ceiling counts what is attached right now (see
		// DiskGateway::attach), so the other order would spend the last free places on files that are on
		// their way out: a document sitting at the ceiling where one write swaps one file for another would
		// find no room for the new file, then drop the old one — and the new file would stay closed to the
		// document's readers until some later write. Nothing is lost by going in this order either, because
		// what is detached here is exactly what the text no longer names.
		$this->diskGateway->detach($stale);

		// Attaching is creation, and creation is exactly what the flag gates (see Configuration). A token
		// can reach the text with the feature off — an import, a REST write, a paste out of a document
		// written while it was on — and making the attachment then would hand the file to every reader of
		// this document through a feature the portal has switched off. Detaching stays unconditional:
		// attachments already made keep following the text, and the resolve keeps describing them.
		if ($actorId > 0 && Configuration::isDiskAttachmentsEnabled())
		{
			$this->diskGateway->attach(
				$documentId,
				array_values(array_diff($attachable, array_keys($existing))),
				$actorId,
			);
		}
	}

	private function reportFailure(int $documentId, \Throwable $error): void
	{
		try
		{
			$this->documentRepository->markDerivedStale([$documentId]);

			\CEventLog::Add([
				'SEVERITY' => \CEventLog::SEVERITY_ERROR,
				'AUDIT_TYPE_ID' => 'NOTE_DISK_ATTACHMENT_SYNC_ERROR',
				'MODULE_ID' => 'note',
				'DESCRIPTION' => 'disk attachment sync failed for document ' . $documentId . ': '
					. $error->getMessage(),
			]);
		}
		catch (\Throwable)
		{
			// Both the mark and the log write to the database, so a database that is down takes them with
			// it. Nothing is left to try, and the write that asked for this sync has already landed - that
			// is what has to survive.
		}
	}
}
