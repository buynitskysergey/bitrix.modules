<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Integration\Landing;

use Bitrix\Landing\Internals\FileTable;
use Bitrix\Main\Loader;

/**
 * Read-only proxy over b_landing_file (block <-> file linkage).
 *
 * ENTITY_TYPE codes (see \Bitrix\Landing\File): 'B' - block, 'L' - landing, 'S' - site.
 *
 * Not `final`, for the same reason as its sibling readers ([P3.T12]): a thin proxy over the ORM
 * holds no invariant of its own, and doubling the one read it is built on is how the batching
 * below is tested without landing fixtures.
 */
class FileReader
{
	private const ENTITY_TYPE_BLOCK = 'B';
	private const ENTITY_TYPE_PAGE = 'L';
	private const ENTITY_TYPE_SITE = 'S';

	/** How many block ids one read of b_landing_file carries. */
	private const BLOCK_CHUNK_SIZE = 300;

	/** @var array<int, int[]> block id => its FILE_IDs, for the blocks already read */
	private array $filesByBlock = [];

	/**
	 * Returns FILE_IDs attached to a block, in deterministic order.
	 *
	 * A block is read once: every converter asks about the block it is converting, and one page can
	 * route its blocks through several of them. {@see preloadForBlocks} answers a whole batch of
	 * pages ahead of the conversion, and then this costs no query at all.
	 *
	 * @return int[]
	 */
	public function filesForBlock(int $blockId): array
	{
		if ($blockId <= 0)
		{
			return [];
		}

		if (!array_key_exists($blockId, $this->filesByBlock))
		{
			$this->readBlocks([$blockId]);
		}

		return $this->filesByBlock[$blockId] ?? [];
	}

	/**
	 * Reads the file links of a whole set of blocks at once, so that the per-block question every
	 * converter asks is answered from memory afterwards. A batch of a hundred pages carries about a
	 * thousand blocks, which is a thousand round trips to b_landing_file when each is asked for on
	 * its own.
	 *
	 * @param int[] $blockIds
	 */
	public function preloadForBlocks(array $blockIds): void
	{
		$blockIds = array_values(array_diff(self::positiveIds($blockIds), array_keys($this->filesByBlock)));
		foreach (array_chunk($blockIds, self::BLOCK_CHUNK_SIZE) as $chunk)
		{
			$this->readBlocks($chunk);
		}
	}

	/**
	 * @param int[] $blockIds already positive and unique
	 */
	private function readBlocks(array $blockIds): void
	{
		if ($blockIds === [])
		{
			return;
		}

		// A block with no file of its own is an answer too, or it would be asked about again.
		foreach ($blockIds as $blockId)
		{
			$this->filesByBlock[$blockId] ??= [];
		}

		foreach ($this->readBlockFileRows($blockIds) as $row)
		{
			$blockId = (int)($row['ENTITY_ID'] ?? 0);
			$fileId = (int)($row['FILE_ID'] ?? 0);
			if ($fileId > 0 && isset($this->filesByBlock[$blockId]))
			{
				$this->filesByBlock[$blockId][] = $fileId;
			}
		}
	}

	/**
	 * The one read this proxy is built on. Ordered by ID, so the files of a block arrive in the
	 * order the converters bind them to the images of its markup, whether the block was asked
	 * about alone or as part of a batch.
	 *
	 * @param int[] $blockIds
	 * @return array<int, array{ENTITY_ID:string|int, FILE_ID:string|int}>
	 */
	protected function readBlockFileRows(array $blockIds): array
	{
		if (!Loader::includeModule('landing'))
		{
			return [];
		}

		return FileTable::getList([
			'select' => ['ENTITY_ID', 'FILE_ID'],
			'filter' => [
				'=ENTITY_ID' => $blockIds,
				'=ENTITY_TYPE' => self::ENTITY_TYPE_BLOCK,
			],
			'order' => ['ID' => 'ASC'],
		])->fetchAll();
	}

	/**
	 * @param int[] $ids
	 * @return int[]
	 */
	private static function positiveIds(array $ids): array
	{
		return array_values(array_unique(array_filter(
			array_map('intval', $ids),
			static fn(int $id): bool => $id > 0,
		)));
	}

	/**
	 * What a core file is attached to on the landing side, grouped by the kind of the entity: blocks,
	 * pages and sites. The reverse of {@see filesForBlock}, and the way a caller finds out which base a
	 * file belongs to - a file of no base at all comes back as three empty lists.
	 *
	 * @return array{B:int[], L:int[], S:int[]}
	 */
	public function entityLinksForFile(int $fileId): array
	{
		$links = [
			self::ENTITY_TYPE_BLOCK => [],
			self::ENTITY_TYPE_PAGE => [],
			self::ENTITY_TYPE_SITE => [],
		];

		if ($fileId <= 0 || !Loader::includeModule('landing'))
		{
			return $links;
		}

		$rows = FileTable::getList([
			'select' => ['ENTITY_ID', 'ENTITY_TYPE'],
			'filter' => ['=FILE_ID' => $fileId],
		])->fetchAll();

		foreach ($rows as $row)
		{
			$entityType = (string)($row['ENTITY_TYPE'] ?? '');
			$entityId = (int)($row['ENTITY_ID'] ?? 0);
			if ($entityId > 0 && isset($links[$entityType]))
			{
				$links[$entityType][] = $entityId;
			}
		}

		return array_map(
			static fn(array $ids): array => array_values(array_unique($ids)),
			$links,
		);
	}
}
