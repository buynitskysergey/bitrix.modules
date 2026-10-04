<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Document;

use Bitrix\Main\Localization\Loc;
use Bitrix\Note\Internal\Model\Document;
use Bitrix\Note\Internal\Model\DocumentTable;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Service\User\SystemUser;

/**
 * Owns the "main document" invariant: exactly one per collection, IS_MAIN='Y',
 * PARENT_ID=NULL, carrying the knowledge base description. Creation is idempotent
 * (create-if-not-exists), so it is safe to call on every collection-create path and
 * from the backfill agent. Read helpers here (and the repository methods they wrap)
 * deliberately bypass MainDocumentFilter.
 */
final class MainDocumentService
{
	public function __construct(
		private readonly DocumentRepository $repository = new DocumentRepository(),
	)
	{
	}

	public function getMainDocumentId(int $collectionId): ?int
	{
		return $this->repository->findMainDocumentIdByCollectionId($collectionId);
	}

	/**
	 * Create-if-not-exists. Returns the existing main document id when one already exists,
	 * otherwise creates an empty main document and returns its id. Null on invalid input
	 * or persistence failure. Callers own the surrounding transaction.
	 */
	public function ensureMainDocument(int $collectionId, int $authorId = SystemUser::ID): ?int
	{
		if ($collectionId <= 0)
		{
			return null;
		}

		$existingId = $this->repository->findMainDocumentIdByCollectionId($collectionId);
		if ($existingId !== null)
		{
			return $existingId;
		}

		$document = DocumentTable::createObject()
			->setCollectionId($collectionId)
			->setParentId(null)
			->setTitle($this->mainDocumentTitle())
			->setMarkdown('')
			->setContentFormat(DocumentTable::CONTENT_FORMAT_YJS)
			->setPosition(0)
			->setIsArchived(false)
			->setIsMain(true)
			->setCreatedBy($authorId)
			->setUpdatedBy($authorId)
		;

		$saveResult = $this->repository->save($document);
		if (!$saveResult->isSuccess())
		{
			return null;
		}

		$saved = $saveResult->getData()['document'] ?? null;
		if (!$saved instanceof Document)
		{
			return null;
		}

		$id = (int)$saved->getId();

		return $id > 0 ? $id : null;
	}

	/**
	 * Batch main-document meta (id + hasDescription) for a set of collections; one query.
	 *
	 * @param int[] $collectionIds
	 * @return array<int, array{id: int, hasDescription: bool}> keyed by collectionId
	 */
	public function getSummaryByCollectionIds(array $collectionIds): array
	{
		return $this->repository->getMainDocumentSummaryByCollectionIds($collectionIds);
	}

	/**
	 * Batch raw MARKDOWN of the main document for a set of collections; one query.
	 *
	 * @param int[] $collectionIds
	 * @return array<int, string> raw MARKDOWN keyed by collectionId
	 */
	public function getMarkdownByCollectionIds(array $collectionIds): array
	{
		return $this->repository->getMainDocumentMarkdownByCollectionIds($collectionIds);
	}

	private function mainDocumentTitle(): string
	{
		$title = (string)Loc::getMessage('NOTE_MAIN_DOCUMENT_TITLE');

		return $title !== '' ? $title : 'Knowledge base description';
	}
}
