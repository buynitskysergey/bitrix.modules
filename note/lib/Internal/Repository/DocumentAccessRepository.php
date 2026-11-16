<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Repository;

use Bitrix\Note\Internal\Access\Service\DocumentAccessService;
use Bitrix\Note\Internal\Model\DocumentAccessTable;

/**
 * Pages of the document ACL table, as opposed to the answers about one document
 * {@see DocumentAccessService} gives.
 */
final class DocumentAccessRepository
{
	// Ceiling of the widened portion, see the refill in listGrantedDocumentIdsPage().
	private const MAX_CHUNK = 500;

	/**
	 * One page of the documents the reader holds a grant of their own on, the grants themselves
	 * leading: SUBJECT_CODE IN (...) AND LEVEL >= VIEW is the head of IX_NOTE_DOC_ACCESS_SUBJECT,
	 * so the page costs the number of grants the reader has and not the number of documents the
	 * portal has. The same set expressed as a condition over the documents costs the other one.
	 *
	 * A document has a row per (code, source) and a reader has many codes, so the portion is folded
	 * by DOCUMENT_ID before the limit applies - a page of fifty means fifty documents.
	 *
	 * The refill reads past the rows an explicit deny cancels, and it is the only place that sees
	 * them: the caller is handed the documents that survived and would count a run of ten thousand
	 * cancelled grants as nothing at all. So the scan is bounded here, by the budget the caller hands
	 * down, and the page reports how far it looked and where it stopped - a caller resuming from the
	 * last document it kept would read the cancelled run again on every page.
	 *
	 * @param string[] $codesPersonal access codes without '*'
	 * @param int|null $afterId last document of the previous page, exclusive
	 * @param int $scanBudget rows of the ACL this call is allowed to look at
	 * @return array{ids: int[], scanned: int, lastId: int|null, exhausted: bool} ids descending;
	 *         lastId is the document the scan stopped on and the place to carry on from, null when
	 *         the call read nothing; exhausted tells the end of the stream from the end of the budget
	 */
	public function listGrantedDocumentIdsPage(
		array $codesPersonal,
		?int $afterId,
		int $limit,
		int $scanBudget = self::MAX_CHUNK,
	): array
	{
		$codes = $this->normalizeCodes($codesPersonal);
		if (empty($codes) || $limit <= 0 || $scanBudget <= 0)
		{
			return ['ids' => [], 'scanned' => 0, 'lastId' => null, 'exhausted' => true];
		}

		$cursor = ($afterId !== null && $afterId > 0) ? $afterId : null;
		$step = $limit;
		$granted = [];
		$scanned = 0;
		$lastId = null;
		$exhausted = false;

		while (count($granted) < $limit && $scanned < $scanBudget)
		{
			$asked = min($step, $scanBudget - $scanned);
			$candidates = $this->fetchCandidateIds($codes, $cursor, $asked);
			$scanned += count($candidates);
			if (empty($candidates))
			{
				$exhausted = true;
				break;
			}

			$cursor = (int)end($candidates);
			$lastId = $cursor;
			$kept = $this->keepGranted($candidates, $codes);
			foreach ($candidates as $documentId)
			{
				if (isset($kept[$documentId]))
				{
					$granted[] = $documentId;
				}
			}

			if (count($candidates) < $asked)
			{
				// The stream ran out inside this portion, so a short page here means the end of it
				// and not a page cut short by the denies.
				$exhausted = true;
				break;
			}

			$step = empty($kept)
				// A portion of nothing but denied documents: widen the step, or a long run of them
				// would cost one round per page worth of rows.
				? min($step * 2, self::MAX_CHUNK)
				: max($limit - count($granted), 1)
			;
		}

		if (count($granted) > $limit)
		{
			// The widened portion kept more than the page holds. The surplus is dropped rather than
			// remembered, so the scan position steps back to the last document the page carries -
			// otherwise the next call would start past documents nobody ever answered with.
			$lastId = $granted[$limit - 1];
			$exhausted = false;
		}

		return [
			'ids' => array_slice($granted, 0, $limit),
			'scanned' => $scanned,
			'lastId' => $lastId,
			'exhausted' => $exhausted,
		];
	}

	/**
	 * @param string[] $codes
	 * @return int[]
	 */
	private function fetchCandidateIds(array $codes, ?int $afterId, int $limit): array
	{
		$query = DocumentAccessTable::query()
			->setSelect(['DOCUMENT_ID'])
			->setDistinct()
			->whereIn('SUBJECT_CODE', $codes)
			->where('LEVEL', '>=', DocumentAccessService::LEVEL_VIEW)
			->addOrder('DOCUMENT_ID', 'DESC')
			->setLimit($limit)
		;
		if ($afterId !== null && $afterId > 0)
		{
			$query->where('DOCUMENT_ID', '<', $afterId);
		}

		return array_map(static fn(array $row): int => (int)$row['DOCUMENT_ID'], $query->fetchAll());
	}

	/**
	 * Which documents of the portion the reader really reaches, by the one formula
	 * {@see DocumentAccessService::reduceDocumentLevel()} holds: an explicit deny cancels the
	 * explicit grant and never the inherited one.
	 *
	 * A point read over the portion already chosen, keyed by (DOCUMENT_ID, SUBJECT_CODE, ...), and
	 * not a NOT IN sub-query over the whole table. It brings the positive rows along with the
	 * denies because the formula has to know which of the two kinds the positive one was.
	 *
	 * @param int[] $documentIds
	 * @param string[] $codes
	 * @return array<int, true>
	 */
	private function keepGranted(array $documentIds, array $codes): array
	{
		$rows = DocumentAccessTable::query()
			->setSelect(['DOCUMENT_ID', 'LEVEL', 'SOURCE_DOCUMENT_ID'])
			->whereIn('DOCUMENT_ID', $documentIds)
			->whereIn('SUBJECT_CODE', $codes)
			->fetchAll()
		;

		$byDocument = [];
		foreach ($rows as $row)
		{
			$byDocument[(int)$row['DOCUMENT_ID']][] = [
				'level' => (int)$row['LEVEL'],
				'source' => (int)$row['SOURCE_DOCUMENT_ID'],
			];
		}

		$kept = [];
		foreach ($byDocument as $documentId => $documentRows)
		{
			if (DocumentAccessService::reduceDocumentLevel($documentRows) >= DocumentAccessService::LEVEL_VIEW)
			{
				$kept[$documentId] = true;
			}
		}

		return $kept;
	}

	/**
	 * @param string[] $codes
	 * @return string[]
	 */
	private function normalizeCodes(array $codes): array
	{
		return array_values(array_unique(array_filter(
			$codes,
			static fn($code): bool => is_string($code) && $code !== '' && $code !== '*',
		)));
	}
}
