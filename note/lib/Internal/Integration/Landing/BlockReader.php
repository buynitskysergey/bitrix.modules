<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Integration\Landing;

use Bitrix\Landing\Internals\BlockTable;
use Bitrix\Main\Loader;

/**
 * Read-only proxy over b_landing_block.
 *
 * Not `final`, for the same reason as its sibling readers ([P3.T12]): a thin proxy over the ORM
 * holds no invariant of its own, and doubling it is how the assembly of a document body is tested
 * without landing fixtures.
 */
class BlockReader
{
	/**
	 * Returns active draft blocks for a batch of pages, ordered by LID, then SORT ASC.
	 * The caller groups by LID and feeds each group to the markdown transformer.
	 *
	 * Mirrors how landing renders a knowledge base (draft mode): blocks are read
	 * from the *draft* copy (PUBLIC='N') - see \Bitrix\Landing\Block::fillLanding().
	 * PUBLIC='N' must stay (not be dropped): a published page keeps two block copies
	 * (PUBLIC='N' draft + PUBLIC='Y' published), so an unfiltered read would duplicate
	 * every block.
	 *
	 * ACTIVE='Y' is filtered here, unlike in fillLanding(), which hands inactive blocks
	 * to the view and lets it render one in edit mode only (\Bitrix\Landing\Block::view()).
	 * A reader of the base never sees an inactive block, so importing one would expose
	 * content its author hid to everyone with access to the new base. Page activity is a
	 * different matter and stays unfiltered ({@see PageReader::listForSite}): a knowledge
	 * base page is visible to readers regardless of it.
	 *
	 * @param int[] $pageIds
	 * @return array<int, array{ID:int, LID:int, CODE:string, CONTENT:string, ANCHOR:?string, SORT:int, DESIGNED:string, SOURCE_PARAMS:?array}>
	 */
	public function listForPages(array $pageIds): array
	{
		$pageIds = array_values(array_unique(array_map('intval', $pageIds)));
		$pageIds = array_filter($pageIds, static fn(int $v): bool => $v > 0);

		if (empty($pageIds) || !Loader::includeModule('landing'))
		{
			return [];
		}

		$rows = BlockTable::getList([
			'select' => ['ID', 'LID', 'CODE', 'CONTENT', 'ANCHOR', 'SORT', 'DESIGNED', 'SOURCE_PARAMS'],
			'filter' => [
				'=LID' => $pageIds,
				'=ACTIVE' => 'Y',
				'=DELETED' => 'N',
				'=PUBLIC' => 'N',
			],
			'order' => ['LID' => 'ASC', 'SORT' => 'ASC', 'ID' => 'ASC'],
		])->fetchAll();

		$blocks = [];
		foreach ($rows as $row)
		{
			$blocks[] = [
				'ID' => (int)$row['ID'],
				'LID' => (int)$row['LID'],
				'CODE' => (string)($row['CODE'] ?? ''),
				'CONTENT' => (string)($row['CONTENT'] ?? ''),
				'ANCHOR' => $row['ANCHOR'] !== null ? (string)$row['ANCHOR'] : null,
				'SORT' => (int)($row['SORT'] ?? 0),
				'DESIGNED' => (string)($row['DESIGNED'] ?? 'N'),
				'SOURCE_PARAMS' => is_array($row['SOURCE_PARAMS'] ?? null) ? $row['SOURCE_PARAMS'] : null,
			];
		}

		return $blocks;
	}

	/**
	 * The pages the given blocks belong to (b_landing_block.LID), whatever state those blocks are in.
	 *
	 * Unfiltered on purpose, unlike {@see listForPages}: this answers where a block lives and not
	 * whether its content is converted, and the caller asking it - the attachment step, checking that a
	 * file is a file of the base it converts - would otherwise read a file of a deleted block of that
	 * very base as a file of somebody else.
	 *
	 * @param int[] $blockIds
	 * @return int[]
	 */
	public function pageIdsForBlocks(array $blockIds): array
	{
		$blockIds = array_values(array_unique(array_filter(
			array_map('intval', $blockIds),
			static fn(int $v): bool => $v > 0,
		)));

		if (empty($blockIds) || !Loader::includeModule('landing'))
		{
			return [];
		}

		$rows = BlockTable::getList([
			'select' => ['LID'],
			'filter' => ['=ID' => $blockIds],
		])->fetchAll();

		$pageIds = [];
		foreach ($rows as $row)
		{
			$pageId = (int)($row['LID'] ?? 0);
			if ($pageId > 0)
			{
				$pageIds[] = $pageId;
			}
		}

		return array_values(array_unique($pageIds));
	}
}
