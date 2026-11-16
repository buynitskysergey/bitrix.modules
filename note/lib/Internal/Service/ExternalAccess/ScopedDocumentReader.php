<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\ExternalAccess;

use Bitrix\Note\Internal\Model\Document;
use Bitrix\Note\Internal\Repository\DocumentRepository;

/**
 * Reads documents inside the scope of one public link, and nowhere else.
 *
 * The reader is built only from the link context, the knowledge base is wired in, and there is no
 * method without a scope: an action added later cannot reach someone else's document even if its
 * author never thought about isolation. Nothing empty is explained - someone else's, an archived,
 * a deleted, an orphaned and the hidden main document all come back as null, and the action
 * answers "not found" for all of them alike.
 */
final class ScopedDocumentReader
{
	public function __construct(
		private readonly PublicScope $scope,
		private readonly DocumentRepository $repository = new DocumentRepository(),
	) {}

	/**
	 * The document with its content, for the actions that serve that content. Everything that only
	 * asks whether a document is inside the scope asks hasDocument() instead: the body of a document
	 * is the largest thing the table stores, and a yes-or-no answer must not cost a read of it.
	 */
	public function getDocument(int $documentId): ?Document
	{
		return $this->repository->getLiveInCollection($this->scope->getCollectionId(), $documentId);
	}

	/**
	 * Whether the scope holds this document - the same question, asked where the answer is used as
	 * yes or no: mentions, backlinks, the files of a document and every picture served from it.
	 */
	public function hasDocument(int $documentId): bool
	{
		return $this->repository->existsLiveInCollection($this->scope->getCollectionId(), $documentId);
	}

	/**
	 * The parent of a document as far as the visitor may know it: the id survives only while the
	 * parent is itself inside the scope and alive, and is dropped otherwise.
	 *
	 * An id of something the visitor cannot open is not a harmless extra - it confirms that the
	 * document above exists, and beyond the scope of a link nothing may be told apart from what
	 * does not exist at all. The price is one read by the primary key per document opened.
	 */
	public function resolveVisibleParentId(Document $document): ?int
	{
		$parentId = (int)$document->getParentId();

		return $parentId > 0 && $this->hasDocument($parentId) ? $parentId : null;
	}
}
