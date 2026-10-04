<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Document;

use Bitrix\Main\ORM\Query\Query;
use Bitrix\Note\Internal\Model\DocumentTable;

/**
 * Excludes the per-collection "main document" (the knowledge base description carrier)
 * from any DocumentTable listing/tree/count query.
 *
 * Unlike RecycleBinFilter (which LEFT JOINs b_note_recycle_bin and checks NULL), the
 * main flag lives on b_note_document itself, so a plain column predicate is enough -
 * no join required. This is the single place that knows how to drop main documents
 * from listings; repositories must not scatter the IS_MAIN literal by hand.
 */
class MainDocumentFilter
{
	/**
	 * Apply only to queries on DocumentTable. Adds WHERE IS_MAIN = 'N'.
	 */
	public function applyExclusion(Query $query): void
	{
		$query->where('IS_MAIN', DocumentTable::IS_MAIN_NO);
	}
}
