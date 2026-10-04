<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Repository;

use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Note\Internal\Model\DocumentFileTable;
use Bitrix\Note\Internal\Util\IdNormalizer;

class DocumentFileLinkRepository
{
	private const DELETE_FILTER_CHUNK_SIZE = 500;

	public function link(int $documentId, int $fileId, int $userId): Result
	{
		$result = new Result();
		if ($documentId <= 0 || $fileId <= 0 || $userId <= 0)
		{
			$result->addError(new Error('Invalid document file link parameters.'));

			return $result;
		}

		$linkedDocumentId = $this->getDocumentIdByFileId($fileId);
		if ($linkedDocumentId !== null && $linkedDocumentId !== $documentId)
		{
			$result->addError(new Error('File is already linked to another document.'));

			return $result;
		}

		if ($linkedDocumentId === $documentId)
		{
			return $result;
		}

		$addResult = DocumentFileTable::add([
			'DOCUMENT_ID' => $documentId,
			'FILE_ID' => $fileId,
			'CREATED_BY' => $userId,
		]);
		if (!$addResult->isSuccess())
		{
			$result->addErrors($addResult->getErrors());
		}

		return $result;
	}

	public function unlink(int $documentId, int $fileId): Result
	{
		$result = new Result();
		if ($documentId <= 0 || $fileId <= 0)
		{
			$result->addError(new Error('Invalid document file unlink parameters.'));

			return $result;
		}

		$deleteResult = DocumentFileTable::delete([
			'DOCUMENT_ID' => $documentId,
			'FILE_ID' => $fileId,
		]);
		if (!$deleteResult->isSuccess())
		{
			$result->addErrors($deleteResult->getErrors());
		}

		return $result;
	}

	public function getByDocumentIds(array $documentIds): array
	{
		$normalizedDocumentIds = IdNormalizer::normalize($documentIds);
		if (empty($normalizedDocumentIds))
		{
			return [];
		}

		$items = DocumentFileTable::getList([
			'select' => ['DOCUMENT_ID', 'FILE_ID'],
			'filter' => ['=DOCUMENT_ID' => $normalizedDocumentIds],
		])->fetchCollection();

		return $this->collectionToRows($items);
	}

	/**
	 * Keyset page of distinct DOCUMENT_IDs that own at least one linked file, DOCUMENT_ID > $afterId,
	 * ascending, capped at $limit. Feeds the one-time OrphanFileCleanupAgent, which walks only the
	 * documents that actually have files (a small subset of all documents) instead of scanning
	 * everything. GROUP BY on the selected column keeps it MySQL 5.6 / PostgreSQL compatible.
	 *
	 * @return int[]
	 */
	public function listDocumentIdsAfter(int $afterId, int $limit): array
	{
		if ($limit <= 0)
		{
			return [];
		}

		$rows = DocumentFileTable::getList([
			'select' => ['DOCUMENT_ID'],
			'filter' => ['>DOCUMENT_ID' => max($afterId, 0)],
			'group' => ['DOCUMENT_ID'],
			'order' => ['DOCUMENT_ID' => 'ASC'],
			'limit' => $limit,
		])->fetchAll();

		return array_map(static fn(array $row): int => (int)$row['DOCUMENT_ID'], $rows);
	}

	public function getByDocumentAndFileIds(int $documentId, array $fileIds): array
	{
		$normalizedFileIds = IdNormalizer::normalize($fileIds);
		if ($documentId <= 0 || empty($normalizedFileIds))
		{
			return [];
		}

		$items = DocumentFileTable::getList([
			'select' => ['DOCUMENT_ID', 'FILE_ID'],
			'filter' => [
				'=DOCUMENT_ID' => $documentId,
				'=FILE_ID' => $normalizedFileIds,
			],
		])->fetchCollection();

		return $this->collectionToRows($items);
	}

	public function deleteByDocumentIds(array $documentIds): Result
	{
		$result = new Result();
		$normalizedDocumentIds = IdNormalizer::normalize($documentIds);
		if (empty($normalizedDocumentIds))
		{
			$result->addError(new Error('Invalid document ids for document file unlink.'));

			return $result;
		}

		$this->deleteByFilterInChunks(
			$normalizedDocumentIds,
			static fn(array $chunk): array => ['=DOCUMENT_ID' => $chunk],
			$result,
		);

		return $result;
	}

	public function deleteByDocumentAndFileIds(int $documentId, array $fileIds): Result
	{
		$result = new Result();
		$normalizedFileIds = IdNormalizer::normalize($fileIds);
		if ($documentId <= 0 || empty($normalizedFileIds))
		{
			$result->addError(new Error('Invalid document file unlink parameters.'));

			return $result;
		}

		$this->deleteByFilterInChunks(
			$normalizedFileIds,
			static fn(array $chunk): array => [
				'=DOCUMENT_ID' => $documentId,
				'=FILE_ID' => $chunk,
			],
			$result,
		);

		return $result;
	}

	public function isLinked(int $documentId, int $fileId): bool
	{
		if ($documentId <= 0 || $fileId <= 0)
		{
			return false;
		}

		return DocumentFileTable::getByPrimary([
			'DOCUMENT_ID' => $documentId,
			'FILE_ID' => $fileId,
		])->fetchObject() !== null;
	}

	public function getDocumentIdByFileId(int $fileId): ?int
	{
		if ($fileId <= 0)
		{
			return null;
		}

		$fileLink = DocumentFileTable::getList([
			'select' => ['DOCUMENT_ID'],
			'filter' => ['=FILE_ID' => $fileId],
			'limit' => 1,
		])->fetchObject();

		$documentId = $fileLink ? (int)$fileLink->getDocumentId() : 0;

		return $documentId > 0 ? $documentId : null;
	}

	public function getLinkByFileId(int $fileId): ?array
	{
		if ($fileId <= 0)
		{
			return null;
		}

		$fileLink = DocumentFileTable::getList([
			'select' => ['DOCUMENT_ID', 'FILE_ID'],
			'filter' => ['=FILE_ID' => $fileId],
			'limit' => 1,
		])->fetchObject();
		if ($fileLink === null)
		{
			return null;
		}

		$documentId = (int)$fileLink->getDocumentId();
		$fileId = (int)$fileLink->getFileId();
		if ($documentId <= 0 || $fileId <= 0)
		{
			return null;
		}

		return [
			'DOCUMENT_ID' => $documentId,
			'FILE_ID' => $fileId,
		];
	}

	/**
	 * Batch sibling of getLinkByFileId(): resolves the owning DOCUMENT_ID for many
	 * fileIds in one query. Returns a fileId => DOCUMENT_ID map; unlinked ids are absent.
	 *
	 * @return array<int, int>
	 */
	public function getLinksByFileIds(array $fileIds): array
	{
		$normalizedFileIds = IdNormalizer::normalize($fileIds);
		if (empty($normalizedFileIds))
		{
			return [];
		}

		$map = [];
		$items = DocumentFileTable::getList([
			'select' => ['DOCUMENT_ID', 'FILE_ID'],
			'filter' => ['=FILE_ID' => $normalizedFileIds],
		])->fetchCollection();

		foreach ($items as $item)
		{
			$documentId = (int)$item->getDocumentId();
			$fileId = (int)$item->getFileId();
			if ($documentId > 0 && $fileId > 0)
			{
				$map[$fileId] = $documentId;
			}
		}

		return $map;
	}

	private function collectionToRows($collection): array
	{
		$result = [];
		foreach ($collection as $item)
		{
			$documentId = (int)$item->getDocumentId();
			$fileId = (int)$item->getFileId();
			if ($documentId <= 0 || $fileId <= 0)
			{
				continue;
			}

			$result[] = [
				'DOCUMENT_ID' => $documentId,
				'FILE_ID' => $fileId,
			];
		}

		return $result;
	}

	private function deleteByFilterInChunks(array $ids, callable $filterBuilder, Result $result): void
	{
		$connection = Application::getConnection();
		$connection->startTransaction();
		try
		{
			foreach (array_chunk($ids, self::DELETE_FILTER_CHUNK_SIZE) as $idChunk)
			{
				DocumentFileTable::deleteByFilter($filterBuilder($idChunk));
			}
			$connection->commitTransaction();
		}
		catch (\Throwable $exception)
		{
			$connection->rollbackTransaction();
			$result->addError(new Error($exception->getMessage()));
		}
	}
}
