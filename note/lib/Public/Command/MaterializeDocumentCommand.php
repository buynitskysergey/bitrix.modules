<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Command;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;
use Bitrix\Main\SystemException;
use Bitrix\Note\Internal\Exceptions\DocumentArchivedException;
use Bitrix\Note\Internal\Exceptions\DocumentInRecycleBinException;
use Bitrix\Note\Internal\Model\DocumentTable;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Repository\DocumentUpdateRepository;
use Bitrix\Note\Internal\Service\Collaboration\DocumentLockService;
use Bitrix\Note\Internal\Service\Document\DocumentCardMetaResolver;
use Bitrix\Note\Internal\Service\RecycleBin\RecycleBinFilter;

/**
 * [API-01] Cheap, non-destructive projection refresh: writes the fresh MARKDOWN, content date and
 * materialization cursor for a document without draining the patch log, snapshotting a version,
 * emitting a content-settled event or recording analytics. Shares the 'compact' lock and the
 * forward-only guard (materializeProjection) with compaction, so a stale write never rolls the
 * projection back. loadPatches is not needed by construction — the client supplies the built markdown.
 *
 * Reports in `applied` whether the write actually landed, and alongside it the card preview of the
 * stored text, so a caller can refresh a document card without re-reading the projection.
 *
 * Takes no actor on purpose. Nothing here is attributed to anyone: the projection write moves neither
 * UPDATED_BY nor UPDATED_AT, and no version, event or analytics record is produced. Authorship of a text
 * edit is settled by compaction, which does take a user id.
 */
class MaterializeDocumentCommand extends AbstractCommand
{
	public function __construct(
		private readonly int $documentId,
		private readonly string $markdown,
		private readonly int $uptoId,
		private readonly DocumentRepository $documentRepository = new DocumentRepository(),
		private readonly DocumentUpdateRepository $updateRepository = new DocumentUpdateRepository(),
		private readonly DocumentLockService $lockService = new DocumentLockService(),
		private readonly RecycleBinFilter $recycleBinFilter = new RecycleBinFilter(),
		private readonly DocumentCardMetaResolver $cardMetaResolver = new DocumentCardMetaResolver(),
	) {}

	protected function execute(): Result
	{
		if (!$this->lockService->acquireLock($this->documentId))
		{
			// Another holder of the 'compact' lock (materialization or compaction) is advancing the
			// projection right now: skip this tick quietly, no write.
			$result = new Result();
			$result->setData(['locked' => true]);

			return $result;
		}

		try
		{
			$excerpt = $this->materialize();
		}
		finally
		{
			$this->lockService->releaseLock($this->documentId);
		}

		$result = new Result();
		$result->setData(
			$excerpt === null
				? ['applied' => false]
				: ['applied' => true, 'excerpt' => $excerpt],
		);

		return $result;
	}

	/**
	 * @return string|null Card preview of the text just written, empty string for a document without
	 *   preview-worthy content; NULL when nothing was written and the stored projection still stands.
	 */
	private function materialize(): ?string
	{
		// Archive/recycle-bin block materialization exactly as they block compaction: a document on its
		// way out must not have its projection rewritten. Read uncached so the guards are fresh.
		$document = $this->documentRepository->getMetaById(
			$this->documentId,
			['ID', 'IS_ARCHIVED', 'CONTENT_FORMAT', 'TITLE'],
			useCache: false,
		);
		if ($document === null)
		{
			throw new SystemException('Document not found');
		}

		// A document that is not collaborative has no projection to refresh: its MARKDOWN is the text
		// itself, written by whoever last overwrote it. The tab asking for this materialization is one
		// that has not learned of the overwrite yet, and its markdown is the discarded CRDT text.
		//
		// The cursor alone cannot refuse it. An overwrite pins the cursor to the journal head and drains
		// the journal, but a savePatch that read the old format before the overwrite committed can still
		// insert its row afterwards - and that row lifts the journal head back above the watermark, so the
		// clamp lets this cursor through and the forward-only guard sees it move forward. The format is
		// what settles it: the document stopped being collaborative, so nothing collaborative may write.
		//
		// Silent, like the lock miss above: the tab is about to be reloaded by the overwrite push, and
		// there is nothing for it to do differently in the meantime.
		$format = (string)$document->getContentFormat();
		if ($format !== DocumentTable::CONTENT_FORMAT_YJS && $format !== DocumentTable::CONTENT_FORMAT_JSON)
		{
			return null;
		}

		if ($this->recycleBinFilter->isInRecycleBin($this->documentId))
		{
			throw new DocumentInRecycleBinException();
		}

		if ($document->getIsArchived())
		{
			throw new DocumentArchivedException();
		}

		// The cursor arrives from the client and nothing else vouches for it, so it goes through the
		// clamp shared with compaction (see DocumentRepository::clampMaterializationCursor).
		$uptoId = DocumentRepository::clampMaterializationCursor(
			$this->uptoId,
			$this->updateRepository->getLastId($this->documentId),
			$this->documentRepository->getMaterializedUptoId($this->documentId),
		);

		// The forward-only guard decides whether this write lands; either outcome writes no journal,
		// version or event. A stale cursor is refused, and the caller has to know: the client marks the
		// text materialized on the answer and would stop resending a projection nobody stored.
		if (!$this->documentRepository->materializeProjection($this->documentId, $this->markdown, $uptoId))
		{
			return null;
		}

		// Pure computation over what was just written - no query of its own, no side effect.
		return $this->cardMetaResolver->buildExcerpt((string)$document->getTitle(), $this->markdown);
	}
}
