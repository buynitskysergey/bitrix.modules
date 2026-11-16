<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\ExternalAccess;

use Bitrix\Main\Type\DateTime;
use Bitrix\Note\Internal\Model\Document;
use Bitrix\Note\Internal\Model\DocumentTable;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Repository\DocumentUpdateRepository;

/**
 * Builds the answer of the public circuit.
 *
 * The mapper puts in only what is listed instead of cleaning extras out of the answer of the
 * ordinary circuit: the production mapDocument() carries views, the last change with its author,
 * subscriptions, favorites and backlinks, and a field that is never built cannot leak.
 *
 * Breadcrumbs are deliberately absent - the client builds them from the tree.
 */
final class PublicResponseMapper
{
	public function __construct(
		private readonly DocumentUpdateRepository $updateRepository = new DocumentUpdateRepository(),
		private readonly DocumentRepository $documentRepository = new DocumentRepository(),
	) {}

	/**
	 * @param int|null $visibleParentId The parent as the visitor may know it, resolved by
	 *                                  ScopedDocumentReader. The raw field of the document is not
	 *                                  read here: it would carry the id of a parent outside the
	 *                                  scope of the link.
	 * @return array{
	 *     id: int,
	 *     parentId: int|null,
	 *     title: string,
	 *     contentFormat: string,
	 *     updatedAt: string,
	 *     yjsState?: string,
	 *     markdown?: array|string,
	 *     collaboration?: array{patches: array<int, array{patch: string}>},
	 * }
	 */
	public function mapDocument(Document $document, ?int $visibleParentId): array
	{
		return [
			'id' => (int)$document->getId(),
			'parentId' => $visibleParentId,
			'title' => (string)$document->getTitle(),
			...$this->mapContent($document),
		];
	}

	/**
	 * The content of one document as the public circuit serves it: the mechanics of the ordinary
	 * answer are unchanged - `yjsState` for a document under collaborative editing, `markdown`
	 * otherwise, exactly one of the two.
	 *
	 * A snapshot alone is not the text. `YJS_STATE` is written by compaction, so between two folds it
	 * stands for the document as it was, and everything typed since lives in the journal. The ordinary
	 * circuit hands both to the editor and lets it merge them; a visitor was getting the snapshot
	 * alone and therefore waited for a fold that has nothing to do with him - the author pressed
	 * "done", and the link kept showing the text of before. So the journal travels with the snapshot
	 * here too, and the reader merges it exactly the way an employee's editor does.
	 *
	 * What the journal must not carry outwards is who wrote each patch: the visitor is anonymous and
	 * the names and ids of the employees editing right now are none of his business. The cursor of the
	 * journal is left out for the same reason it is not needed - nothing here writes back.
	 *
	 * @return array{
	 *     contentFormat: string,
	 *     updatedAt: string,
	 *     yjsState?: string,
	 *     markdown?: array|string,
	 *     collaboration?: array{patches: array<int, array{patch: string}>},
	 * }
	 */
	public function mapContent(Document $document): array
	{
		$payload = [
			'contentFormat' => (string)$document->getContentFormat(),
			'updatedAt' => $document->getUpdatedAt()->format('c'),
		];

		$documentId = (int)$document->getId();

		// The text is read at the branch that serves it and never with the row: a document under
		// collaborative editing takes neither branch - its text is the snapshot below - so carrying
		// MARKDOWN in every row was reading the second largest column of the table to discard it.
		if ($document->getContentFormat() !== DocumentTable::CONTENT_FORMAT_YJS)
		{
			$payload['markdown'] = $this->documentRepository->getRawMarkdown($documentId) ?? '';

			return $payload;
		}

		// Journal first, snapshot second, and never the other way round. Compaction folds the journal
		// into the snapshot and drains it in one transaction: read the snapshot first and it can be the
		// one from before the fold, while the journal read after it is already empty - the window that
		// was folded away belongs to neither, and the visitor gets a text that never existed. In this
		// order the snapshot is at worst newer than the journal, which only means a patch is applied
		// twice, and applying the same update twice changes nothing in a CRDT.
		$journal = $this->updateRepository->listPatchesWithoutAuthor($documentId);
		$yjsState = $this->documentRepository->getYjsState($documentId);

		if ($yjsState === null || $yjsState === '')
		{
			// The fallback of an empty snapshot, unchanged: the same text, read the same way as above.
			$payload['markdown'] = $this->documentRepository->getRawMarkdown($documentId) ?? '';

			return $payload;
		}

		$payload['yjsState'] = $yjsState;

		$patches = [];
		foreach ($journal as $row)
		{
			if ($row['patch'] !== '')
			{
				$patches[] = ['patch' => $row['patch']];
			}
		}

		if ($patches !== [])
		{
			$payload['collaboration'] = ['patches' => $patches];
		}

		// UPDATED_AT belongs to the row, and a patch does not touch the row: a description edited a
		// minute ago keeps answering with the time the document was created until something else saves
		// it. The journal knows better, so the later of the two is the answer.
		$lastPatchTime = $this->resolveLastPatchTime($journal);
		if ($lastPatchTime !== null && $lastPatchTime->getTimestamp() > $document->getUpdatedAt()->getTimestamp())
		{
			$payload['updatedAt'] = $lastPatchTime->format('c');
		}

		return $payload;
	}

	/**
	 * @param array<int, array{patch: string, createdAt: ?DateTime}> $journal
	 */
	private function resolveLastPatchTime(array $journal): ?DateTime
	{
		$last = null;
		foreach ($journal as $row)
		{
			$createdAt = $row['createdAt'];
			if ($createdAt === null)
			{
				continue;
			}

			if ($last === null || $createdAt->getTimestamp() > $last->getTimestamp())
			{
				$last = $createdAt;
			}
		}

		return $last;
	}
}
