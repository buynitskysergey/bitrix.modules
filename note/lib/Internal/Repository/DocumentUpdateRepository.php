<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Repository;

use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\Result;
use Bitrix\Note\Internal\Model\DocumentTable;
use Bitrix\Note\Internal\Model\DocumentUpdateTable;
use Bitrix\Note\Internal\Service\RecycleBin\RecycleBinFilter;

class DocumentUpdateRepository
{
	/**
	 * Reserved for the one refusal that means "this document is not in a collaborative format any more".
	 * The client hangs its rescue path on it - it stops queueing and keeps what the user typed - so it must
	 * not be reported for a document that is merely archived, trashed or gone: each of those has a
	 * lifecycle handling of its own, and the rescue path would tear down a session that only needed to wait.
	 */
	public const ERROR_DOCUMENT_NOT_EDITABLE = 'NOTE_DOCUMENT_NOT_EDITABLE';
	public const ERROR_DOCUMENT_NOT_FOUND = 'NOTE_DOCUMENT_NOT_FOUND';
	public const ERROR_DOCUMENT_ARCHIVED = 'NOTE_DOCUMENT_ARCHIVED';
	public const ERROR_DOCUMENT_TRASHED = 'NOTE_DOCUMENT_TRASHED';

	private ?RecycleBinFilter $recycleBinFilter = null;

	private function recycleBinFilter(): RecycleBinFilter
	{
		return $this->recycleBinFilter ??= new RecycleBinFilter();
	}

	public function getByDocumentId(int $documentId): array
	{
		return DocumentUpdateTable::getList([
			'select' => ['ID', 'PATCH', 'USER_ID'],
			'filter' => ['=DOCUMENT_ID' => $documentId],
			'order' => ['ID' => 'ASC'],
		])->fetchAll();
	}

	/**
	 * Guard: the row is inserted only if the document exists, is not archived, is not in trash and
	 * still keeps a collaborative CONTENT_FORMAT.
	 *
	 * The format is what protects an overwrite (REST rewrite, version restore) from being undone by a
	 * tab that has not learned of it yet. The overwrite demotes the document to plain markdown, drops
	 * YJS_STATE and clears the journal; a patch accepted afterwards would sit above the pinned cursor,
	 * so the sender's own materialization would clear the forward-only guard and put the discarded CRDT
	 * text back over the new one - and its compaction would resurrect the snapshot with it. Refusing the
	 * patch here is the only place that cannot be bypassed by a client.
	 *
	 * The refusal lasts as long as the document stays out of the format, which is until somebody rebuilds
	 * a baseline from its new text (CollaborationEligibility). It is therefore a window, not a wall, and
	 * what closes the window for the stale tab is the refusal itself: it is reported distinguishably
	 * (savePatchAction) so the tab stops queueing, drops the patches of the replaced text and keeps what
	 * it holds for the user instead.
	 *
	 * Each of the four states is reported by a code of its own. They are not interchangeable to the caller:
	 * the format refusal is permanent for as long as the demotion lasts and asks the client to rescue what
	 * it holds, while an archived or trashed document is a lifecycle state the client already reports in its
	 * own way. Collapsed into one code, every one of them would tear down a live editing session.
	 *
	 * Check-then-insert is not atomic; the tiny race window can only leave an orphan patch row,
	 * which compact/hard-delete cleanup removes anyway.
	 */
	public function add(int $documentId, int $userId, string $patch): Result
	{
		$result = new Result();

		// The three states the row itself can tell apart come out of one query - the accepted patch, which
		// is the case that runs every couple of seconds per editor, must not pay for the distinction. The
		// trash is left to the same exclusion as before: it hides the row, so the fourth state is asked for
		// only once the row is already missing, on a path that ends in a refusal anyway.
		$query = DocumentTable::query()
			->setSelect(['ID', 'IS_ARCHIVED', 'CONTENT_FORMAT'])
			->where('ID', $documentId);
		$this->recycleBinFilter()->applyExclusion($query);
		$document = $query->fetchObject();

		if ($document === null)
		{
			$result->addError($this->recycleBinFilter()->isInRecycleBin($documentId)
				? new Error('Document is in the recycle bin', self::ERROR_DOCUMENT_TRASHED)
				: new Error('Document not found', self::ERROR_DOCUMENT_NOT_FOUND));

			return $result;
		}

		if ($document->getIsArchived())
		{
			$result->addError(new Error('Document is archived', self::ERROR_DOCUMENT_ARCHIVED));

			return $result;
		}

		$format = (string)$document->getContentFormat();
		if ($format !== DocumentTable::CONTENT_FORMAT_YJS && $format !== DocumentTable::CONTENT_FORMAT_JSON)
		{
			$result->addError(new Error('Document is not editable', self::ERROR_DOCUMENT_NOT_EDITABLE));

			return $result;
		}

		$addResult = DocumentUpdateTable::add([
			'DOCUMENT_ID' => $documentId,
			'USER_ID' => $userId,
			'PATCH' => $patch,
		]);

		if (!$addResult->isSuccess())
		{
			$result->addErrors($addResult->getErrors());

			return $result;
		}

		$result->setData(['id' => (int)$addResult->getId()]);

		return $result;
	}

	/**
	 * Deletes the patch window (DOCUMENT_ID, ID<=maxId) and returns the distinct USER_ID
	 * set of that window (co-authors), captured BEFORE the delete. Authors are read via a
	 * GROUP BY (cross-DB distinct) rather than DELETE...RETURNING, which MySQL lacks.
	 *
	 * Result.data['authorIds'] = int[] distinct USER_ID of the window.
	 */
	public function deleteUpToIdReturningAuthors(int $documentId, int $maxId): Result
	{
		$result = new Result();

		$rows = DocumentUpdateTable::query()
			->setSelect(['USER_ID'])
			->where('DOCUMENT_ID', $documentId)
			->where('ID', '<=', $maxId)
			->setGroup(['USER_ID'])
			->fetchAll()
		;

		$authorIds = array_values(array_map(
			static fn(array $row): int => (int)$row['USER_ID'],
			$rows,
		));

		DocumentUpdateTable::deleteByFilter([
			'=DOCUMENT_ID' => $documentId,
			'<=ID' => $maxId,
		]);

		$result->setData(['authorIds' => $authorIds]);

		return $result;
	}

	public function hasAnyByDocumentId(int $documentId): bool
	{
		if ($documentId <= 0)
		{
			return false;
		}

		$row = DocumentUpdateTable::getList([
			'select' => ['ID'],
			'filter' => ['=DOCUMENT_ID' => $documentId],
			'limit' => 1,
		])->fetch();

		return $row !== false;
	}

	public function deleteByDocumentId(int $documentId): void
	{
		$this->deleteByDocumentIds([$documentId]);
	}

	/**
	 * @param int[] $documentIds
	 */
	public function deleteByDocumentIds(array $documentIds): void
	{
		$normalized = array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $documentIds),
			static fn(int $id): bool => $id > 0,
		)));
		if (empty($normalized))
		{
			return;
		}

		$connection = Application::getConnection();
		$placeholders = implode(',', $normalized);
		$connection->queryExecute("DELETE FROM b_note_document_updates WHERE DOCUMENT_ID IN ({$placeholders})");
	}

	/**
	 * [EVENT-01] The patch that precedes $beforeId within one document: the highest id of this document
	 * below it, or NULL when the patch is the first one left in the journal.
	 *
	 * Asked AFTER the insert, never before. Two concurrent saves for the same document would otherwise
	 * both read the same predecessor, and the one that landed second would claim to continue a patch it
	 * does not follow — its sender would then take the chain for unbroken and materialize a markdown
	 * missing the other patch. Anchored to the new id, the answer cannot be shared by two patches.
	 */
	public function getPreviousId(int $documentId, int $beforeId): ?int
	{
		$row = DocumentUpdateTable::getList([
			'select' => ['MAX_ID'],
			'filter' => ['=DOCUMENT_ID' => $documentId, '<ID' => $beforeId],
			'runtime' => [
				new ExpressionField('MAX_ID', 'MAX(%s)', ['ID']),
			],
		])->fetch();

		return $row === false || $row['MAX_ID'] === null ? null : (int)$row['MAX_ID'];
	}

	/**
	 * [API-02] Threshold probe for the backstop, which only ever asks "is the journal at least this
	 * long?". Counting the whole journal after every single patch makes each save in a long editing run
	 * more expensive than the last, because COUNT visits every entry the document has.
	 *
	 * The offset is not a jump: the engine still steps over the skipped entries one by one. What it buys
	 * is a ceiling - the walk stops at $threshold entries however far past it the journal has grown, so
	 * the cost of the probe stops growing where COUNT would keep climbing. And it stays inside the index:
	 * DOCUMENT_ID and ID are both in IX_NOTE_DOC_UPD_DOCID, so no table row is read.
	 */
	public function hasAtLeast(int $documentId, int $threshold): bool
	{
		if ($threshold <= 0)
		{
			return true;
		}

		$row = DocumentUpdateTable::getList([
			'select' => ['ID'],
			'filter' => ['=DOCUMENT_ID' => $documentId],
			'order' => ['ID' => 'ASC'],
			'limit' => 1,
			'offset' => $threshold - 1,
		])->fetch();

		return $row !== false;
	}

	public function getLastId(int $documentId): ?int
	{
		$row = DocumentUpdateTable::getList([
			'select' => ['MAX_ID'],
			'filter' => ['=DOCUMENT_ID' => $documentId],
			'runtime' => [
				new ExpressionField('MAX_ID', 'MAX(%s)', ['ID']),
			],
		])->fetch();

		if ($row === false || $row['MAX_ID'] === null)
		{
			return null;
		}

		return (int)$row['MAX_ID'];
	}
}
