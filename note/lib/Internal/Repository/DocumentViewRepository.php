<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Repository;

use Bitrix\Main\Application;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\DateTime;
use Bitrix\Note\Internal\Model\DocumentViewTable;

/**
 * [P3.T1-T3] b_note_document_view access: upsert on awareness join, keyset read
 * for the widget, and hard-delete cascade.
 */
class DocumentViewRepository
{
	/**
	 * Upsert one (documentId, userId) view row via a cross-DB MERGE — VIEWED_AT is
	 * refreshed on every call, a reconnect resends awareness `join` and must not add a row.
	 */
	public function track(int $documentId, int $userId, DateTime $viewedAt): void
	{
		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();

		[$sql] = $helper->prepareMerge(
			DocumentViewTable::getTableName(),
			['DOCUMENT_ID', 'USER_ID'],
			[
				'DOCUMENT_ID' => $documentId,
				'USER_ID' => $userId,
				'VIEWED_AT' => $viewedAt,
			],
			['VIEWED_AT' => $viewedAt],
		);

		if ($sql !== '')
		{
			$connection->queryExecute($sql);
		}
	}

	/**
	 * Keyset page of viewers, most-recently-viewed first. Reads via
	 * IX_NOTE_DOC_VIEW_RECENT (DOCUMENT_ID, VIEWED_AT, USER_ID); the USER_ID DESC
	 * tie-break keeps the cursor unambiguous when two views share a VIEWED_AT second.
	 *
	 * @param array{viewedAt: string, userId: int}|null $afterCursor
	 * @return array{rows: array<int, array{userId: int, viewedAt: DateTime}>, hasNextPage: bool}
	 */
	public function listViewers(int $documentId, int $limit, ?array $afterCursor = null): array
	{
		$query = DocumentViewTable::query()
			->setSelect(['USER_ID', 'VIEWED_AT'])
			->where('DOCUMENT_ID', $documentId)
			->addOrder('VIEWED_AT', 'DESC')
			->addOrder('USER_ID', 'DESC')
			->setLimit($limit + 1)
		;

		$cursorUserId = isset($afterCursor['userId']) ? (int)$afterCursor['userId'] : 0;
		$cursorViewedAtRaw = is_string($afterCursor['viewedAt'] ?? null) ? $afterCursor['viewedAt'] : '';
		// A malformed client cursor must degrade to "first page", not throw a 500
		// (mirrors FeedProvider::parseCursor); an unparseable date leaves the cursor unset.
		$cursorViewedAt = null;
		if ($cursorViewedAtRaw !== '')
		{
			try
			{
				$cursorViewedAt = DateTime::createFromPhp(new \DateTime($cursorViewedAtRaw));
			}
			catch (\Throwable)
			{
				$cursorViewedAt = null;
			}
		}
		if ($cursorUserId > 0 && $cursorViewedAt !== null)
		{
			$query->where(
				Query::filter()->logic('or')
					->where('VIEWED_AT', '<', $cursorViewedAt)
					->where(
						Query::filter()
							->where('VIEWED_AT', $cursorViewedAt)
							->where('USER_ID', '<', $cursorUserId)
					)
			);
		}

		$rows = [];
		$result = $query->exec();
		while ($row = $result->fetch())
		{
			$viewedAt = $row['VIEWED_AT'] ?? null;
			if (!($viewedAt instanceof DateTime))
			{
				$viewedAt = $viewedAt ? DateTime::createFromUserTime((string)$viewedAt) : new DateTime();
			}

			$rows[] = [
				'userId' => (int)$row['USER_ID'],
				'viewedAt' => $viewedAt,
			];
		}

		$hasNextPage = count($rows) > $limit;
		if ($hasNextPage)
		{
			$rows = array_slice($rows, 0, $limit);
		}

		return ['rows' => $rows, 'hasNextPage' => $hasNextPage];
	}

	/**
	 * Number of unique viewers = number of rows for the document (PK guarantees uniqueness).
	 */
	public function countUnique(int $documentId): int
	{
		$row = DocumentViewTable::getList([
			'select' => ['CNT'],
			'filter' => ['=DOCUMENT_ID' => $documentId],
			'runtime' => [
				new ExpressionField('CNT', 'COUNT(*)'),
			],
		])->fetch();

		return $row ? (int)$row['CNT'] : 0;
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

		DocumentViewTable::deleteByFilter(['@DOCUMENT_ID' => $normalized]);
	}
}
