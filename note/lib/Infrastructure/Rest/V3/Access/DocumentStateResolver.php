<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Access;

use Bitrix\Note\Internal\Repository\CollectionRepository;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Repository\RecycleBinRepository;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Exception\EntityNotFoundException;

/**
 * The single place where a REST document or file action learns what state a document is in and
 * whether it may be touched.
 *
 * Nothing is decided here: the state comes from the repositories and the right comes from the gates
 * that already own it. What the resolver does own is the order of the questions. The recycle bin is
 * asked about before the ordinary ACL, because a document in the bin must not become visible through
 * a grant on the document itself - asking the other way round is what leaks it today.
 *
 * A caller who may not see a document is answered exactly as a caller who asked about an id that was
 * never used: the existence of a document is closed information in itself.
 */
class DocumentStateResolver
{
	private const META_SELECT = [
		'ID', 'COLLECTION_ID', 'PARENT_ID', 'TITLE', 'POSITION', 'IS_ARCHIVED', 'IS_MAIN',
		'CREATED_BY', 'UPDATED_BY', 'CREATED_AT', 'UPDATED_AT', 'CONTENT_UPDATED_AT',
	];

	/** @var array<string, ResolvedDocument> resolved within this request only */
	private array $memo = [];

	public function __construct(
		private readonly DocumentRepository $documents = new DocumentRepository(),
		private readonly RecycleBinRepository $recycleBin = new RecycleBinRepository(),
		private readonly CollectionRepository $collections = new CollectionRepository(),
		private readonly DocumentAccessGate $access = new CurrentUserAccessGate(),
	) {}

	public function resolve(int $documentId, int $userId, DocumentIntent $intent): ResolvedDocument
	{
		$key = $documentId . ':' . $userId . ':' . $intent->name;
		if (isset($this->memo[$key]))
		{
			return $this->memo[$key];
		}

		return $this->memo[$key] = $this->doResolve($documentId, $userId, $intent);
	}

	private function doResolve(int $documentId, int $userId, DocumentIntent $intent): ResolvedDocument
	{
		// Uncached on purpose: this row decides whether the caller may see or write the document, and a
		// row served from a cache that is a TTL behind would decide it by yesterday's state.
		$meta = $documentId > 0
			? $this->documents->getMetaById($documentId, self::META_SELECT, useCache: false)
			: null;

		if ($meta === null || $meta->getIsMain())
		{
			throw new EntityNotFoundException($documentId);
		}

		$collectionId = (int)$meta->getCollectionId();

		$record = $this->recycleBin->getByDocumentId($documentId);
		if ($record !== null)
		{
			if (!$this->access->canViewInRecycleBin($userId, $record))
			{
				throw new EntityNotFoundException($documentId);
			}

			if ($intent === DocumentIntent::Write)
			{
				throw new EntityNotFoundException($documentId);
			}

			$state = $this->collections->exists($collectionId)
				? DocumentState::Trashed
				: DocumentState::Orphan;

			return new ResolvedDocument(
				meta: $meta,
				state: $state,
				canWrite: false,
				// The collection id is answered as it is even for an orphan: it is what lets the client
				// make sense of the orphan marker.
				sharedAccess: false,
				recycleBinRecord: $record,
			);
		}

		$isArchived = (bool)$meta->getIsArchived();
		$snapshot = $this->access->snapshot($documentId, $collectionId, $isArchived);
		if (!$snapshot['canView'])
		{
			throw new EntityNotFoundException($documentId);
		}

		$state = $isArchived ? DocumentState::Archived : DocumentState::Live;
		$canWrite = $state === DocumentState::Live && $snapshot['canEdit'];

		if ($intent === DocumentIntent::Write && !$canWrite)
		{
			// Two refusals that look alike and are not. A document the caller may see but may not edit
			// is refused by right - hiding it from somebody already looking at it explains nothing. A
			// document that is not in a writable state at all answers as missing, the way the archive
			// and the recycle bin answer everywhere else.
			throw $state === DocumentState::Live
				? new AccessDeniedException()
				: new EntityNotFoundException($documentId)
			;
		}

		return new ResolvedDocument(
			meta: $meta,
			state: $state,
			canWrite: $canWrite,
			sharedAccess: (bool)$snapshot['sharedAccess'],
		);
	}
}
