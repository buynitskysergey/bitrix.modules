<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Repository\Mapper;

use Bitrix\Main\Text\Emoji;
use Bitrix\Note\Internal\Entity\Search\SearchIndexEntry;

final class SearchIndexEntryMapper
{
	public static function convertFromOrm(array $row): SearchIndexEntry
	{
		return new SearchIndexEntry(
			(int)($row['DOCUMENT_ID'] ?? 0),
			Emoji::decode((string)($row['BODY'] ?? '')),
		);
	}

	/**
	 * @return array{DOCUMENT_ID: int, BODY: string}
	 */
	public static function convertToOrm(SearchIndexEntry $entity): array
	{
		// BODY is written via NoteDocumentSearchTable::merge(), which builds raw
		// INSERT..ON DUPLICATE KEY UPDATE SQL and bypasses ORM save modifiers, so a
		// 4-byte emoji would silently truncate the tail (utf8mb3 connection). Encode
		// explicitly here; convertFromOrm decodes on read.
		return [
			'DOCUMENT_ID' => $entity->getDocumentId(),
			'BODY' => Emoji::encode($entity->getBody()),
		];
	}
}
