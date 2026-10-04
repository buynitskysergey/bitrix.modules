<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Flipchart\DualMode;

use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Disk\TypeFile;

/**
 * The emptiness precondition of stage 1: a project may become a pilot only while its group storage
 * holds no board at all.
 *
 * The query is portal-local: it asks no service, does not depend on SITE_ID and does not depend on
 * any instance's garbage collection. Selection goes by file type rather than extension, covers the
 * trash can and every subfolder, and is deliberately made before canonicalisation: a symlink to
 * somebody else's board is a row of the right type and makes the project non-empty.
 */
final class PilotStorageInspector
{
	/**
	 * The caller needs the fact of non-emptiness and a few examples to name in a refusal, so one row
	 * past the sample is enough to answer both.
	 */
	public const SAMPLE_SIZE = 20;

	public function findBoards(int $storageId): BoardSample
	{
		$rows = ObjectTable::getList([
			'select' => ['ID'],
			'filter' => [
				'=STORAGE_ID' => $storageId,
				'=TYPE' => ObjectTable::TYPE_FILE,
				'=TYPE_FILE' => TypeFile::FLIPCHART,
			],
			'order' => ['ID' => 'ASC'],
			'limit' => self::SAMPLE_SIZE + 1,
		])->fetchAll();

		$ids = array_map(
			static fn (array $row): int => (int)$row['ID'],
			array_slice($rows, 0, self::SAMPLE_SIZE),
		);

		return new BoardSample($ids, count($rows) > self::SAMPLE_SIZE);
	}
}
