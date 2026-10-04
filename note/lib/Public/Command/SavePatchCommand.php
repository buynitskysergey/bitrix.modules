<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Command;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Repository\DocumentUpdateRepository;
use Bitrix\Note\Internal\Service\Collaboration\PushNotificationService;

class SavePatchCommand extends AbstractCommand
{
	// [API-02] Server-side journal backstop: past this many rows the sender is told to compact. Sized
	// so normal editing (client compacts ~every 3 min) never trips it, only a stuck journal does; also
	// caps the replay cost when the document is reopened. Server-owned — a client counter drifts on a
	// lost pull message. Tuned by measurement.
	private const JOURNAL_BACKSTOP_ROWS = 500;

	private readonly int $documentId;
	private readonly int $userId;
	private readonly string $patch;
	private readonly ?string $cursor;
	private readonly DocumentUpdateRepository $updateRepository;
	private readonly PushNotificationService $pushService;
	private readonly DocumentRepository $documentRepository;

	public function __construct(
		int $documentId,
		int $userId,
		string $patch,
		?string $cursor = null,
		?DocumentUpdateRepository $updateRepository = null,
		?PushNotificationService $pushService = null,
		?DocumentRepository $documentRepository = null,
	)
	{
		$this->documentId = $documentId;
		$this->userId = $userId;
		$this->patch = $patch;
		$this->cursor = $cursor;
		$this->updateRepository = $updateRepository ?? new DocumentUpdateRepository();
		$this->pushService = $pushService ?? new PushNotificationService();
		$this->documentRepository = $documentRepository ?? new DocumentRepository();
	}

	protected function execute(): Result
	{
		$addResult = $this->updateRepository->add($this->documentId, $this->userId, $this->patch);
		if (!$addResult->isSuccess())
		{
			$result = new Result();
			$result->addErrors($addResult->getErrors());

			return $result;
		}

		// [EVENT-01] Id of the just-inserted row: pushed to peers so they can detect a gap, and returned
		// to the sender (who does not receive its own patch over pull) so it can advance its applied
		// cursor and form the materialization uptoId.
		$patchId = (int)($addResult->getData()['id'] ?? 0);

		// [EVENT-01] The predecessor within THIS document is what a receiver compares against its own
		// cursor to tell "nothing missed" from "there is a gap" — the ids themselves cannot answer that,
		// coming from a table-wide auto-increment shared with every other document being edited. Read
		// AFTER the insert and anchored to the new id: taken before, two concurrent saves would both see
		// the same predecessor and the second one would claim to continue a patch it does not follow.
		$prevPatchId = $this->updateRepository->getPreviousId($this->documentId, $patchId) ?? 0;

		$journalBaseId = $prevPatchId === 0 ? $this->readJournalBaseId() : 0;

		$this->pushService->sendDocumentPatch(
			$this->documentId,
			$this->userId,
			$this->patch,
			$this->cursor,
			$patchId,
			$prevPatchId,
			$journalBaseId,
		);

		// [API-02] Journal size is measured server-side after the insert; the sender (guaranteed present
		// and write-capable) is the only actor that can build markdown, so it carries the compact hint.
		// Asked as a threshold probe, not a full count: this runs on every patch, and counting a journal
		// that keeps growing would make a long editing run cost more with every keystroke batch.
		$compactSuggested = $this->updateRepository->hasAtLeast($this->documentId, self::JOURNAL_BACKSTOP_ROWS);

		$result = new Result();
		$result->setData([
			'patchId' => $patchId,
			// The sender does not receive its own patch over pull, so it learns the continuity of its
			// own write from here: a prevPatchId ahead of its cursor means it missed someone else's
			// patch and must not report the new id as "everything applied up to".
			'prevPatchId' => $prevPatchId,
			// [EVENT-01] Journal waterline, see readJournalBaseId(). Meaningful only together with a
			// zero prevPatchId; 0 everywhere else, where the receiver has no use for it.
			'journalBaseId' => $journalBaseId,
			'compactSuggested' => $compactSuggested,
		]);

		return $result;
	}

	/**
	 * [EVENT-01] The id below which this document has no journal rows left, i.e. how far the journal has
	 * been cut away. A patch that continues nothing (prevPatchId 0) is ambiguous on its own: the receiver
	 * cannot tell "the rows I had already applied were compacted away" from "the rows I never received
	 * were", and reading it as the former makes it advance its applied cursor over a hole - after which
	 * its own compaction persists that incomplete state as the authoritative one. The waterline settles
	 * the question. Read only in that case: a document row per patch, on a path that runs every couple of
	 * seconds per editor, buys nothing when the predecessor is already known.
	 *
	 * MATERIALIZED_UPTO_ID stands in for the cut level. Compaction drains exactly up to the cursor it
	 * stores, overwrite pins the cursor to the journal head it then clears, and the forward-only guard in
	 * materializeProjection never lets the stored value move back. So the field is never BELOW what was
	 * really cut; an overshoot only costs the receiver one needless snapshot fetch.
	 */
	private function readJournalBaseId(): int
	{
		return $this->documentRepository->getMaterializedUptoId($this->documentId) ?? 0;
	}
}
