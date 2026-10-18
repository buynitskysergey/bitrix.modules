<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Read;

use Bitrix\Note\Infrastructure\Rest\V3\Access\DocumentIntent;
use Bitrix\Note\Infrastructure\Rest\V3\Access\DocumentStateResolver;
use Bitrix\Note\Infrastructure\Rest\V3\Access\ResolvedDocument;
use Bitrix\Note\Internal\Model\Document;
use Bitrix\Note\Internal\Repository\DocumentRepository;

/**
 * Builds the read model of a single document for REST.
 *
 * Neither access nor state is decided here - that belongs to {@see DocumentStateResolver}, which has
 * already read the row by the time this is called. All that is left is the content, and it is the one
 * thing worth a query of its own: the markdown of a document is the heaviest column of the table.
 */
class DocumentReader
{
	public function __construct(
		private readonly DocumentStateResolver $resolver = new DocumentStateResolver(),
		private readonly DocumentRepository $documents = new DocumentRepository(),
	) {}

	public function read(int $documentId, int $userId, DocumentIntent $intent = DocumentIntent::Read): DocumentReadModel
	{
		return $this->fromResolved($this->resolver->resolve($documentId, $userId, $intent));
	}

	/**
	 * The read model of a document a command has just saved.
	 *
	 * The meta is taken from the entity the command returned - asking the database about what it has
	 * just been told is the reading this phase removes. The content is a separate matter: a write that
	 * carried text knows it and passes it in, while a rename never touched the text and has nothing
	 * trustworthy in hand, so for it the content is read, and only the content.
	 *
	 * @param string|null $markdown text the caller has just written, or null to read the stored one
	 */
	public function fromSavedDocument(
		Document $document,
		bool $sharedAccess = false,
		?string $markdown = null,
	): DocumentReadModel
	{
		$markdown ??= $this->documents->getRawMarkdown((int)$document->getId()) ?? '';

		return new DocumentReadModel(
			id: (int)$document->getId(),
			collectionId: $sharedAccess ? null : (int)$document->getCollectionId(),
			parentId: $document->getParentId() !== null ? (int)$document->getParentId() : null,
			title: (string)$document->getTitle(),
			markdown: $markdown,
			position: (int)$document->getPosition(),
			createdBy: (int)$document->getCreatedBy(),
			updatedBy: (int)$document->getUpdatedBy(),
			createdAt: $document->getCreatedAt(),
			updatedAt: $document->getUpdatedAt(),
			contentUpdatedAt: $document->getContentUpdatedAt(),
			// A write only lands on a live document - the commands refuse an archived or trashed one -
			// so the markers are what they are for every answer built here.
			isArchived: false,
		);
	}

	/**
	 * @param bool $withContent false skips the content query entirely - the caller asked for a set of
	 *                          fields that does not include the text, and it is the heaviest column of
	 *                          the table.
	 */
	public function fromResolved(ResolvedDocument $resolved, bool $withContent = true): DocumentReadModel
	{
		$meta = $resolved->meta;

		return new DocumentReadModel(
			id: $resolved->getId(),
			// A document reached through a grant of its own says nothing about the collection holding
			// it: that is privacy, not a projection of the screen.
			collectionId: $resolved->sharedAccess ? null : $resolved->getCollectionId(),
			parentId: $meta->getParentId() !== null ? (int)$meta->getParentId() : null,
			title: (string)$meta->getTitle(),
			markdown: $withContent ? ($this->documents->getRawMarkdown($resolved->getId()) ?? '') : '',
			position: (int)$meta->getPosition(),
			createdBy: (int)$meta->getCreatedBy(),
			updatedBy: (int)$meta->getUpdatedBy(),
			createdAt: $meta->getCreatedAt(),
			updatedAt: $meta->getUpdatedAt(),
			contentUpdatedAt: $meta->getContentUpdatedAt(),
			isArchived: $resolved->isArchived(),
			isTrashed: $resolved->isTrashed(),
			isOrphan: $resolved->isOrphan(),
		);
	}
}
