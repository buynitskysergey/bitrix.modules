<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Repository;

use Bitrix\Main\Application;
use Bitrix\Note\Internal\Model\ImportMapTable;

class ImportMapRepository
{
	public function saveDocumentMapping(string $sourceType, string $externalId, int $documentId, ?string $urlId = null): void
	{
		$this->upsert($sourceType, $externalId, $documentId, null, $urlId);
	}

	public function saveCollectionMapping(string $sourceType, string $externalId, int $collectionId, ?string $urlId = null): void
	{
		$this->upsert($sourceType, $externalId, null, $collectionId, $urlId);
	}

	public function findDocumentId(string $sourceType, string $externalId): ?int
	{
		$row = ImportMapTable::getList([
			'select' => ['DOCUMENT_ID'],
			'filter' => [
				'=SOURCE_TYPE' => $sourceType,
				[
					'LOGIC' => 'OR',
					['=EXTERNAL_ID' => $externalId],
					['=URL_ID' => $externalId],
				],
				'!DOCUMENT_ID' => null,
			],
			'limit' => 1,
		])->fetch();

		return $row ? (int)$row['DOCUMENT_ID'] : null;
	}

	public function findCollectionId(string $sourceType, string $externalId): ?int
	{
		$mapping = $this->findCollectionMapping($sourceType, $externalId);

		return $mapping['collectionId'] ?? null;
	}

	/**
	 * The mapping of a collection with the anchor the conversion wrote, which {@see findCollectionId}
	 * drops. A source can encode something in the anchor that a caller needs back: landing puts the
	 * id of the source site there ({@see \Bitrix\Note\Internal\Service\Import\Source\Landing\LandingId::collectionAnchor}),
	 * so the old address of a base resolves without a query into landing to translate its code.
	 *
	 * @return array{collectionId:int, urlId:?string}|null null when nothing is mapped
	 */
	public function findCollectionMapping(string $sourceType, string $externalId): ?array
	{
		$row = ImportMapTable::getList([
			'select' => ['COLLECTION_ID', 'URL_ID'],
			'filter' => [
				'=SOURCE_TYPE' => $sourceType,
				[
					'LOGIC' => 'OR',
					['=EXTERNAL_ID' => $externalId],
					['=URL_ID' => $externalId],
				],
				'!COLLECTION_ID' => null,
			],
			'limit' => 1,
		])->fetch();

		if ($row === false)
		{
			return null;
		}

		return [
			'collectionId' => (int)$row['COLLECTION_ID'],
			'urlId' => $row['URL_ID'] !== null ? (string)$row['URL_ID'] : null,
		];
	}

	/**
	 * Look up mappings by EXTERNAL_ID (UUID) and/or URL_ID (Outline urlId).
	 * Resulting array is keyed by every identifier that matched a row, so a single
	 * row may appear under both its UUID and urlId keys.
	 *
	 * @param string[] $externalIds
	 * @param string[] $urlIds
	 * @return array<string, array{documentId: ?int, collectionId: ?int}>
	 */
	public function bulkLookup(string $sourceType, array $externalIds, array $urlIds = []): array
	{
		$externalIds = array_values(array_unique(array_filter($externalIds, static fn($v): bool => $v !== null && $v !== '')));
		$urlIds = array_values(array_unique(array_filter($urlIds, static fn($v): bool => $v !== null && $v !== '')));

		if (empty($externalIds) && empty($urlIds))
		{
			return [];
		}

		$orFilter = ['LOGIC' => 'OR'];
		if (!empty($externalIds))
		{
			$orFilter[] = ['@EXTERNAL_ID' => $externalIds];
		}
		if (!empty($urlIds))
		{
			$orFilter[] = ['@URL_ID' => $urlIds];
		}

		$rows = ImportMapTable::getList([
			'select' => ['EXTERNAL_ID', 'URL_ID', 'DOCUMENT_ID', 'COLLECTION_ID'],
			'filter' => [
				'=SOURCE_TYPE' => $sourceType,
				$orFilter,
			],
		])->fetchAll();

		$externalIdLookup = array_flip($externalIds);
		$urlIdLookup = array_flip($urlIds);

		$result = [];
		foreach ($rows as $row)
		{
			$entry = [
				'documentId' => $row['DOCUMENT_ID'] !== null ? (int)$row['DOCUMENT_ID'] : null,
				'collectionId' => $row['COLLECTION_ID'] !== null ? (int)$row['COLLECTION_ID'] : null,
			];

			if (isset($externalIdLookup[$row['EXTERNAL_ID']]))
			{
				$result[$row['EXTERNAL_ID']] = $entry;
			}
			if ($row['URL_ID'] !== null && isset($urlIdLookup[$row['URL_ID']]))
			{
				$result[$row['URL_ID']] = $entry;
			}
		}

		return $result;
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
		$connection->queryExecute("DELETE FROM b_note_import_map WHERE DOCUMENT_ID IN ({$placeholders})");
	}

	public function deleteByCollectionId(int $collectionId): void
	{
		if ($collectionId <= 0)
		{
			return;
		}

		$connection = Application::getConnection();
		$collectionIdSafe = (int)$collectionId;
		$connection->queryExecute("DELETE FROM b_note_import_map WHERE COLLECTION_ID = {$collectionIdSafe}");
	}

	private function upsert(string $sourceType, string $externalId, ?int $documentId, ?int $collectionId, ?string $urlId = null): void
	{
		// Один запрос INSERT ... ON DUPLICATE KEY UPDATE вместо пары SELECT + INSERT/UPDATE.
		// Корректность по NULL: в $updateFields кладём только те колонки, у которых
		// значение не null — так existing-значение противоположной колонки не затирается.
		$insertFields = [
			'SOURCE_TYPE' => $sourceType,
			'EXTERNAL_ID' => $externalId,
			'URL_ID' => $urlId,
			'DOCUMENT_ID' => $documentId,
			'COLLECTION_ID' => $collectionId,
		];

		$updateFields = [];
		if ($documentId !== null)
		{
			$updateFields['DOCUMENT_ID'] = $documentId;
		}
		if ($collectionId !== null)
		{
			$updateFields['COLLECTION_ID'] = $collectionId;
		}
		if ($urlId !== null)
		{
			$updateFields['URL_ID'] = $urlId;
		}

		if (empty($updateFields))
		{
			return;
		}

		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();

		[$sql] = $helper->prepareMerge(
			ImportMapTable::getTableName(),
			['SOURCE_TYPE', 'EXTERNAL_ID'],
			$insertFields,
			$updateFields,
		);

		if ($sql !== '')
		{
			$connection->queryExecute($sql);
		}
	}
}
