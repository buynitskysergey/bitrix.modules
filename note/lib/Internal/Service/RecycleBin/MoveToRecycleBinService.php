<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\RecycleBin;

use Bitrix\Note\Internal\Entity\RecycleBin\RecycleBinRecord;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Repository\RecycleBinRepository;

class MoveToRecycleBinService
{
	public function __construct(
		private readonly RecycleBinRepository $recycleBinRepository = new RecycleBinRepository(),
		private readonly DocumentRepository $documentRepository = new DocumentRepository(),
	) {}

	/**
	 * Move a document subtree (rooted at $rootDocumentId) into the recycle bin.
	 * The root row is recorded with ORIGIN_USER_DELETE; descendants — with ORIGIN_CASCADE_DOCUMENT.
	 * The document rows themselves are not mutated; their COLLECTION_ID/PARENT_ID/POSITION/IS_ARCHIVED
	 * stay as-is and serve as the restore source.
	 *
	 * @return int[] document ids that were placed into the bin
	 */
	public function moveSubtree(int $rootDocumentId, int $userId): array
	{
		$document = $this->documentRepository->getMetaById($rootDocumentId);
		if ($document === null)
		{
			return [];
		}

		$collectionId = (int)$document->getCollectionId();
		$subtreeIds = $this->documentRepository->getSubtreeIds($rootDocumentId, $collectionId);
		if (empty($subtreeIds))
		{
			$subtreeIds = [$rootDocumentId];
		}

		$records = [];
		foreach ($subtreeIds as $docId)
		{
			$docId = (int)$docId;
			$records[] = $docId === $rootDocumentId
				? RecycleBinRecord::createForUserDelete($docId, $userId)
				: RecycleBinRecord::createForCascadeDocument($docId, $userId);
		}

		$this->recycleBinRepository->addBatch($records);

		return $subtreeIds;
	}

	/**
	 * Move a single document (its subtree untouched) into the recycle bin with
	 * ORIGIN_USER_DELETE. Used by no-cascade delete, where the node's direct children have
	 * already been re-hung onto its parent by ReparentService. The document row itself is
	 * not mutated and serves as the restore source.
	 *
	 * @return int[] the trashed document id (the node alone), or [] if it does not exist
	 */
	public function moveNode(int $documentId, int $userId): array
	{
		$document = $this->documentRepository->getMetaById($documentId);
		if ($document === null)
		{
			return [];
		}

		$this->recycleBinRepository->addBatch([
			RecycleBinRecord::createForUserDelete($documentId, $userId),
		]);

		return [$documentId];
	}

	/**
	 * Move every document of a collection (live or archived) into the recycle bin with
	 * ORIGIN_CASCADE_COLLECTION_DELETED. Iterates in keyset chunks of 500 to bound memory.
	 *
	 * @return int[] document ids that were placed into the bin
	 */
	public function moveCollection(int $collectionId, int $userId): array
	{
		$allTrashedIds = [];
		$afterId = 0;

		do
		{
			$chunk = $this->documentRepository->listByCollectionForCascadeDelete($collectionId, $afterId, 500);
			if (empty($chunk))
			{
				break;
			}

			$records = [];
			foreach ($chunk as $row)
			{
				$docId = (int)$row['ID'];
				$records[] = RecycleBinRecord::createForCascadeCollectionDeleted($docId, $userId);
				$allTrashedIds[] = $docId;
			}

			$this->recycleBinRepository->addBatch($records);

			$last = end($chunk);
			$afterId = (int)$last['ID'];
		}
		while (count($chunk) === 500);

		return $allTrashedIds;
	}
}
