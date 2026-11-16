<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Controller;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Note\Infrastructure\Rest\V3\Access\DocumentIntent;
use Bitrix\Note\Infrastructure\Rest\V3\Access\DocumentStateResolver;
use Bitrix\Note\Infrastructure\Rest\V3\Access\ResolvedDocument;
use Bitrix\Note\Infrastructure\Rest\V3\Controller\ActionFilter\NoteRestAccess;
use Bitrix\Note\Infrastructure\Rest\V3\Read\DocumentListCursor;
use Bitrix\Note\Infrastructure\Rest\V3\Read\DocumentListQuery;
use Bitrix\Note\Infrastructure\Rest\V3\Read\DocumentListReader;
use Bitrix\Note\Infrastructure\Rest\V3\Read\DocumentReader;
use Bitrix\Note\Infrastructure\Rest\V3\Read\StateFilter;
use Bitrix\Note\Infrastructure\Rest\V3\Dto\DocumentCursorDto;
use Bitrix\Note\Infrastructure\Rest\V3\Dto\DocumentItemDto;
use Bitrix\Note\Infrastructure\Rest\V3\Dto\Mapping\DocumentMapper;
use Bitrix\Note\Infrastructure\Rest\V3\Exceptions\DocumentHasUnsavedChangesException;
use Bitrix\Note\Infrastructure\Rest\V3\Exceptions\EmptyUpdateException;
use Bitrix\Note\Infrastructure\Rest\V3\Exceptions\InvalidParentException;
use Bitrix\Note\Infrastructure\Rest\V3\Exceptions\MarkdownTooLargeException;
use Bitrix\Note\Infrastructure\Rest\V3\Exceptions\MoveAccessEscalationException;
use Bitrix\Note\Infrastructure\Rest\V3\Exceptions\RestoreTargetRequiredException;
use Bitrix\Note\Infrastructure\Rest\V3\Request\ArchiveDocumentRequest;
use Bitrix\Note\Infrastructure\Rest\V3\Request\ListDocumentsRequest;
use Bitrix\Note\Infrastructure\Rest\V3\Request\MoveDocumentRequest;
use Bitrix\Note\Infrastructure\Rest\V3\Request\RestoreDocumentRequest;
use Bitrix\Note\Infrastructure\Rest\V3\Request\UpdateDocumentRequest;
use Bitrix\Note\Infrastructure\Rest\V3\Response\DocumentListResponse;
use Bitrix\Note\Infrastructure\Rest\V3\Write\PositionSlotResolver;
use Bitrix\Note\Internal\Access\Service\DocumentAccessService;
use Bitrix\Note\Internal\Exceptions\AccessDeniedException as DomainAccessDeniedException;
use Bitrix\Note\Internal\Exceptions\CollectionNotFoundException;
use Bitrix\Note\Internal\Exceptions\DocumentArchivedException;
use Bitrix\Note\Internal\Exceptions\DocumentHasUnsavedChangesException as DomainDocumentHasUnsavedChangesException;
use Bitrix\Note\Internal\Exceptions\DocumentInRecycleBinException;
use Bitrix\Note\Internal\Exceptions\DocumentNotFoundException;
use Bitrix\Note\Internal\Exceptions\MainDocumentOperationException;
use Bitrix\Note\Internal\Exceptions\MoveAccessEscalationException as DomainMoveAccessEscalationException;
use Bitrix\Note\Internal\Exceptions\OrphanRestoreTargetRequiredException as DomainOrphanRestoreTargetRequiredException;
use Bitrix\Note\Internal\Exceptions\ParentDocumentMismatchException;
use Bitrix\Note\Internal\Model\Document as DocumentEntity;
use Bitrix\Note\Internal\Model\DocumentTable;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Service\Analytics\AnalyticsDictionary;
use Bitrix\Note\Internal\Service\Analytics\AnalyticsService;
use Bitrix\Note\Internal\Service\Document\Position\PositionService;
use Bitrix\Note\Public\Command\ArchiveDocumentCommand;
use Bitrix\Note\Public\Command\CreateDocumentCommand;
use Bitrix\Note\Public\Command\DeleteDocumentCommand;
use Bitrix\Note\Public\Command\MoveDocumentCommand;
use Bitrix\Note\Public\Command\OverwriteDocumentContentCommand;
use Bitrix\Note\Public\Command\RestoreDocumentCommand;
use Bitrix\Note\Public\Command\RestoreDocumentFromRecycleBinCommand;
use Bitrix\Note\Public\Command\UpdateDocumentCommand;
use Bitrix\Note\Public\Provider\Param\Document\DocumentLimits;
use Bitrix\Note\Public\Service\AccessService;
use Bitrix\Main\Command\Exception\CommandException;
use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Attribute\Idempotent;
use Bitrix\Rest\V3\Attribute\RequiredGroup;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Exception\EntityNotFoundException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Interaction\Request\AddRequest;
use Bitrix\Rest\V3\Interaction\Request\DeleteRequest;
use Bitrix\Rest\V3\Interaction\Request\GetRequest;
use Bitrix\Rest\V3\Interaction\Response\DeleteResponse;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\UpdateResponse;

#[DtoType(DocumentItemDto::class)]
class Document extends RestController
{
	use CurrentUserAware;

	private ?DocumentStateResolver $stateResolver = null;
	private ?DocumentReader $reader = null;

	protected function getDefaultPreFilters(): array
	{
		return [
			...parent::getDefaultPreFilters(),
			new NoteRestAccess(),
		];
	}

	public function listAction(ListDocumentsRequest $request): DocumentListResponse
	{
		$filter = $request->filter?->getSimpleFilterConditions() ?? [];
		$select = $request->getSelect();

		$result = (new DocumentListReader())->list(
			new DocumentListQuery(
				collectionId: array_key_exists('collectionId', $filter) ? (int)$filter['collectionId'] : null,
				states: StateFilter::parse($request->filter),
				select: $select,
				limit: $request->getLimit(),
				cursor: DocumentListCursor::parse($request->getAfterCursor()),
			),
			$this->getCurrentUserId(),
		);

		return new DocumentListResponse(
			// A page answers with metainformation and never with content. A select that named the text
			// was already refused on the way in, so the narrowing here is about the caller who named
			// nothing: they are given everything the list has, which is everything but the text.
			$this->getDtoMapper()->mapCollection(
				$result->items,
				$select === [] ? DocumentMapper::fieldsWithout(DocumentListQuery::HEAVY_FIELDS) : $select,
			),
			$this->buildCursor($result->nextCursor),
		);
	}

	public function getAction(GetRequest $request): GetResponse
	{
		$resolved = $this->getStateResolver()->resolve(
			(int)$request->id,
			$this->getCurrentUserId(),
			DocumentIntent::Read,
		);

		// view_document for the REST read; the resolver throws on missing/forbidden, so this fires only
		// on success.
		AnalyticsService::documentViewed(true, AnalyticsDictionary::TYPE_REST);

		$fields = $request->select?->getStructuredList() ?? [];

		return new GetResponse($this->getDtoMapper()->mapOne(
			// No `select` means the whole default answer, content included; a `select` without the text
			// means the text is never read at all.
			$this->getReader()->fromResolved($resolved, empty($fields) || in_array('markdown', $fields, true)),
			$fields,
		));
	}

	#[Title('Create a document')]
	#[Description(
		'Creates a document in a collection, optionally under a parent document. The text is passed as '
		. 'markdown and is stored as the document content right away, so the fresh document reads back '
		. 'exactly as it was written. Requires the right to create documents in that collection.'
	)]
	public function addAction(AddRequest $request): GetResponse
	{
		/** @var DocumentItemDto $dto */
		$dto = $request->fields->convertToDto(RequiredGroup::Add->value);

		// The one required field that stays nullable: a document reached through a grant alone answers
		// without a collection id, so the type cannot carry the requirement and the action states it.
		$collectionId = (int)$dto->collectionId;
		if ($collectionId <= 0)
		{
			throw new RequestValidationException([
				new Error('collectionId must be a positive integer', 'collectionId'),
			]);
		}

		// The state of the collection is asked about before the level, the same way the far end of a
		// move is: a collection in the recycle bin takes no new documents, and answering that with "no
		// access" would send the caller asking for a right that would change nothing.
		$this->requireUsableCollection($collectionId);

		try
		{
			AccessService::assertCanCreateInCollection($collectionId);
		}
		catch (DomainAccessDeniedException)
		{
			throw new AccessDeniedException();
		}

		$title = (string)$dto->title;
		$parentId = isset($dto->parentId) ? $dto->parentId : null;
		$markdown = isset($dto->markdown) ? (string)$dto->markdown : '';

		// A main document is never a valid parent - it lives outside the document tree.
		if ($parentId !== null && (new DocumentRepository())->isMainDocument((int)$parentId))
		{
			throw new InvalidParentException();
		}

		// Byte-precise limit (UTF-8): char-based Length cannot express it, so it stays here.
		if ($markdown !== '' && strlen($markdown) > DocumentLimits::MAX_MARKDOWN_BYTES)
		{
			throw new MarkdownTooLargeException(DocumentLimits::MAX_MARKDOWN_BYTES);
		}

		// Non-empty markdown -> finalised MD doc; otherwise default to YJS (collaborative).
		$contentFormat = $markdown !== ''
			? DocumentTable::CONTENT_FORMAT_MD
			: DocumentTable::CONTENT_FORMAT_YJS;

		try
		{
			$result = (new CreateDocumentCommand(
				$collectionId,
				$parentId,
				$title,
				$markdown,
				$this->getCurrentUserId(),
				$contentFormat,
				notifyInitiator: true,
				analyticsType: AnalyticsDictionary::TYPE_REST,
			))->run();
		}
		catch (CommandException $e)
		{
			$this->mapCreateDomainException($e->getPrevious() ?? $e, $collectionId);
		}

		$document = $result->getData()['document'] ?? null;
		if (!$document instanceof DocumentEntity)
		{
			throw new EntityNotFoundException(0);
		}

		// The created document is answered from what the command saved. A creator holds the collection,
		// so nothing about it has to be withheld here.
		return new GetResponse($this->getDtoMapper()->mapOne(
			$this->getReader()->fromSavedDocument($document, markdown: $markdown),
		));
	}

	#[Title('Rename a document or overwrite its text')]
	#[Description(
		'Changes the title, the markdown, or both - at least one of them must be given. Overwriting '
		. 'the text replaces the whole document, so it is refused while somebody is editing it in the '
		. 'collaborative editor and their changes are not yet saved: the answer says so instead of '
		. 'quietly winning the race. The main document of a collection is not editable here. '
		. 'The markdown accepted here is capped at 1 MiB; the editor itself is not, so a document that '
		. 'has grown past that in the editor cannot be overwritten through REST at all - the whole text '
		. 'has to fit in one request.'
	)]
	public function updateAction(UpdateDocumentRequest $request): GetResponse
	{
		$id = $this->requireId($request->id);
		$resolved = $this->resolveVisible($id);

		$items = $request->fields->getItems();
		$hasTitle = array_key_exists('title', $items);
		$hasMarkdown = array_key_exists('markdown', $items);
		if (!$hasTitle && !$hasMarkdown)
		{
			throw new EmptyUpdateException();
		}

		try
		{
			AccessService::assertCanEditDocument($id, (int)$resolved->meta->getCollectionId());
		}
		catch (DomainAccessDeniedException)
		{
			throw new AccessDeniedException();
		}

		// Whether the answer may name the collection. A caller who only holds a grant on the document
		// itself is told nothing about the collection around it - the same rule the read path follows.
		$sharedAccess = $resolved->sharedAccess;

		/** @var DocumentItemDto $dto */
		$dto = $request->fields->convertToDto(RequiredGroup::Update->value);
		$title = $hasTitle ? (string)$dto->title : null;

		if ($hasMarkdown)
		{
			$markdown = (string)$dto->markdown;
			if (strlen($markdown) > DocumentLimits::MAX_MARKDOWN_BYTES)
			{
				throw new MarkdownTooLargeException(DocumentLimits::MAX_MARKDOWN_BYTES);
			}

			try
			{
				$result = (new OverwriteDocumentContentCommand(
					$id,
					$markdown,
					$this->getCurrentUserId(),
					(bool)($request->overwrite ?? false),
					$title,
				))->run();
			}
			catch (CommandException $e)
			{
				$this->mapDocumentDomainException($e->getPrevious() ?? $e, $id);
			}

			// Single edit_text label covers title, content or both; only reached on success.
			AnalyticsService::documentUpdated(AnalyticsDictionary::CHANGE_TYPE_EDIT_TEXT, true, AnalyticsDictionary::TYPE_REST);

			return $this->answerWithSavedDocument($result, $id, $sharedAccess, $markdown);
		}

		try
		{
			$result = (new UpdateDocumentCommand(
				$id,
				(string)$title,
				$this->getCurrentUserId(),
				notifyInitiator: true,
			))->run();
		}
		catch (CommandException $e)
		{
			$this->mapDocumentDomainException($e->getPrevious() ?? $e, $id);
		}

		AnalyticsService::documentUpdated(AnalyticsDictionary::CHANGE_TYPE_EDIT_TEXT, true, AnalyticsDictionary::TYPE_REST);

		return $this->answerWithSavedDocument($result, $id, $sharedAccess);
	}

	#[Idempotent]
	#[Title('Send a document to the archive')]
	#[Description(
		'Archives the document with everything nested under it. An archived document leaves the tree '
		. 'but is still readable through note.document.get, where it reports itself as `isArchived`. '
		. 'Requires the right to edit the collection it belongs to.'
	)]
	public function archiveAction(ArchiveDocumentRequest $request): UpdateResponse
	{
		$id = (int)$request->id;
		$this->assertCanEditOwningCollection($this->resolveVisible($id));

		try
		{
			$result = (new ArchiveDocumentCommand($id, $this->getCurrentUserId(), notifyInitiator: true, analyticsType: AnalyticsDictionary::TYPE_REST))->run();
		}
		catch (CommandException $e)
		{
			$this->mapDocumentDomainException($e->getPrevious() ?? $e, $id);
		}

		if (($result->getData()['success'] ?? false) !== true)
		{
			throw new EntityNotFoundException($id);
		}

		return new UpdateResponse(true);
	}

	#[Idempotent]
	#[Title('Bring a document back from the archive')]
	#[Description(
		'Takes the document and everything nested under it out of the archive and back into the tree. '
		. 'An archived collection around it is lifted out of the archive too - a document returned into '
		. 'one would be invisible anyway. A document that is not in the archive is answered as missing, '
		. 'the mirror of what archiving an already archived one answers: the archive is a state to '
		. 'enter and to leave, not a flag to set and clear. A document in the recycle bin is not in the '
		. 'archive - it is restored with note.document.restore. Requires the right to edit the '
		. 'collection it belongs to.'
	)]
	public function unarchiveAction(ArchiveDocumentRequest $request): UpdateResponse
	{
		$id = (int)$request->id;
		$resolved = $this->resolveVisible($id);
		$this->assertCanEditOwningCollection($resolved);

		// Asked here rather than left to the command, which only looks at the archive flag: a document
		// deleted while archived keeps that flag in the bin, and clearing it there would quietly decide
		// what state the bin gives it back in.
		if (!$resolved->isArchived())
		{
			throw new EntityNotFoundException($id);
		}

		try
		{
			$result = (new RestoreDocumentCommand($id, $this->getCurrentUserId()))->run();
		}
		catch (CommandException $e)
		{
			$this->mapDocumentDomainException($e->getPrevious() ?? $e, $id);
		}

		if (!(($result->getData()['document'] ?? null) instanceof DocumentEntity))
		{
			throw new EntityNotFoundException($id);
		}

		return new UpdateResponse(true);
	}

	#[Idempotent]
	#[Title('Move a document, or change where it sits among its neighbours')]
	#[Description(
		'Moves the document with everything nested under it: to another collection, under another '
		. 'parent, to another place in the list of its neighbours, or all three at once. The request '
		. 'describes where the document lands, not what changes about it - an absent `collectionId` '
		. 'means the collection it is in now, an absent `parentId` means a root of the target '
		. 'collection, so a reorder among children names its parent too. '
		. '`position` is the sort weight the listings answer with: pass a number between the weights of '
		. 'two neighbours to land between them, and omit it to land last. The weight actually stored is '
		. 'recomputed from the neighbours - it may differ from the one asked for - so the answer carries '
		. 'the document as it was saved. '
		. 'Requires the right to edit both the collection the document leaves and the one it enters. A '
		. 'move into a branch shared with a wider audience is refused separately, as '
		. '`NOTE_MOVE_ACCESS_ESCALATION`: it would hand that audience everything being moved.'
	)]
	public function moveAction(MoveDocumentRequest $request): GetResponse
	{
		$id = $request->id;
		$resolved = $this->resolveVisible($id);

		$sourceCollectionId = (int)$resolved->meta->getCollectionId();
		$targetCollectionId = $request->collectionId ?? $sourceCollectionId;
		$this->assertCanEditOwningCollection($resolved);
		if ($targetCollectionId !== $sourceCollectionId)
		{
			$this->requireWritableCollection($targetCollectionId);
		}

		$parentId = $request->parentId;
		if ($parentId !== null)
		{
			$parent = $this->resolveVisible($parentId);
			if ((int)$parent->meta->getCollectionId() !== $targetCollectionId)
			{
				throw new InvalidParentException();
			}
		}

		$slot = (new PositionSlotResolver())->forDocument(
			$targetCollectionId,
			$parentId,
			$id,
			$request->position,
		);

		try
		{
			$result = (new MoveDocumentCommand(
				$id,
				$targetCollectionId,
				$parentId,
				$slot,
				$this->getCurrentUserId(),
			))->run();
		}
		catch (CommandException $e)
		{
			$this->mapMoveDomainException($e->getPrevious() ?? $e, $id);
		}

		$document = $result->getData()['fullDocument'] ?? $result->getData()['document'] ?? null;
		if (!$document instanceof DocumentEntity)
		{
			throw new EntityNotFoundException($id);
		}

		return new GetResponse(
			$this->getDtoMapper()->mapOne(
				$this->getReader()->fromSavedDocument($document, $resolved->sharedAccess),
			),
		);
	}

	#[Title('Move a document to the recycle bin')]
	#[Description(
		'Moves the document and everything nested under it to the recycle bin, from where the web '
		. 'interface can still restore it - this is not a hard delete. A trashed document keeps '
		. 'answering note.document.get and reports itself as `isTrashed`. The main document of a '
		. 'collection cannot be deleted separately: it dies with the collection.'
	)]
	public function deleteAction(DeleteRequest $request): DeleteResponse
	{
		$id = $this->requireId($request->id);
		// Destructive op: requires MANAGE on the owning collection, matching the engine path.
		$this->assertCanEditOwningCollection($this->resolveVisible($id));

		$result = (new DeleteDocumentCommand($id, $this->getCurrentUserId(), notifyInitiator: true, analyticsType: AnalyticsDictionary::TYPE_REST))->run();
		if (($result->getData()['success'] ?? false) !== true)
		{
			throw new EntityNotFoundException($id);
		}

		return new DeleteResponse(true);
	}

	#[Idempotent]
	#[Title('Bring a document back from the recycle bin')]
	#[Description(
		'Takes the document and everything nested under it out of the recycle bin. It returns to the '
		. 'collection it was deleted from, under its original parent whenever that parent is back in '
		. 'place; `collectionId` sends it elsewhere and takes the right to edit that collection. A '
		. 'document whose collection is gone has nowhere to return to and is refused as '
		. '`NOTE_RESTORE_TARGET_REQUIRED` until `collectionId` names one. A document that is not in '
		. 'the bin is answered as missing, and one whose collection is in the bin is refused: the '
		. 'collection comes back whole, so a document pulled out of it now would be missing from it.'
	)]
	public function restoreAction(RestoreDocumentRequest $request): GetResponse
	{
		$id = $request->id;
		$resolved = $this->resolveVisible($id);

		// The resolver has already read the recycle-bin row to answer whether the document is visible
		// at all; its absence means the document is not in the bin.
		$record = $resolved->recycleBinRecord;
		if ($record === null)
		{
			throw new EntityNotFoundException($id);
		}

		// Not the ordinary edit right: who may take a document out of the bin is a question of its own,
		// and the same gate answers it for the web.
		if (!DocumentAccessService::canRestoreFromRecycleBin($this->getCurrentUserId(), $record))
		{
			throw new AccessDeniedException();
		}

		$targetCollectionId = $request->collectionId;
		if ($targetCollectionId !== null)
		{
			$this->requireWritableCollection($targetCollectionId);
		}

		try
		{
			$result = (new RestoreDocumentFromRecycleBinCommand(
				(int)$record->getId(),
				$this->getCurrentUserId(),
				$targetCollectionId,
			))->run();
		}
		catch (CommandException $e)
		{
			$this->mapRestoreDomainException($e->getPrevious() ?? $e, $id);
		}

		if (!$result->isSuccess())
		{
			$this->refuseFailedRestore($result, $id);
		}

		// A reader of its own, not the shared one: the resolver behind it has already answered about
		// this document while it was in the bin, and it answers from memory for the rest of the
		// request - the state that matters here is the one the restore has just produced.
		return new GetResponse(
			$this->getDtoMapper()->mapOne((new DocumentReader())->read($id, $this->getCurrentUserId())),
		);
	}

	/**
	 * The gate every write of a document goes through first, and the reason it is one call rather than
	 * two checks in a row: a write used to answer "no access" about a document the caller was never
	 * allowed to know existed, while a read of the same document answered "not found". Here the
	 * question is only whether the caller may see it at all - the main document, somebody else's, one
	 * hidden in the recycle bin are answered as missing.
	 *
	 * Deliberately the reading intent, not the writing one. Whether the state allows the write is
	 * decided by the command that performs it: delete of an archived document is allowed and archive
	 * of it is not, and that difference lives in the commands, not here.
	 */
	private function resolveVisible(int $id): ResolvedDocument
	{
		return $this->getStateResolver()->resolve($id, $this->getCurrentUserId(), DocumentIntent::Read);
	}

	/**
	 * Archiving and deleting are collection-wide gestures: they take everything nested under the
	 * document with them, so they ask for the right to manage the collection, not the right to edit
	 * this one document. The resolver has already established that the caller may see it, so a refusal
	 * here is honestly about the level.
	 */
	private function assertCanEditOwningCollection(ResolvedDocument $resolved): void
	{
		try
		{
			AccessService::assertCanEditCollection((int)$resolved->meta->getCollectionId());
		}
		catch (DomainAccessDeniedException)
		{
			throw new AccessDeniedException();
		}
	}

	/**
	 * Whether a collection can hold a document that is being put into it at all - asked before any
	 * question about the caller, because the answer is the same for everybody.
	 *
	 * A collection the caller may not open is answered as a missing one, the same way the collection
	 * methods answer about it; so is one in the recycle bin, which for REST has stopped being. An
	 * archived one is refused as a write into an archive, because that is what landing a document
	 * there would be.
	 */
	private function requireUsableCollection(int $collectionId): void
	{
		// The state comes from the access layer rather than from the provider: the write path asks it
		// again further down, and asked this way both questions share one read.
		$state = DocumentAccessService::collectionState($collectionId);
		if ($state === null || !AccessService::canViewCollection($collectionId))
		{
			throw new EntityNotFoundException($collectionId);
		}

		if ($state['trashed'])
		{
			throw new EntityNotFoundException($collectionId);
		}

		if ($state['archived'])
		{
			throw new AccessDeniedException();
		}
	}

	/**
	 * The far end of a move: the collection has to be usable, and the caller has to hold the right to
	 * edit it - a move hands the whole branch over to it.
	 */
	private function requireWritableCollection(int $collectionId): void
	{
		$this->requireUsableCollection($collectionId);

		try
		{
			AccessService::assertCanEditCollection($collectionId);
		}
		catch (DomainAccessDeniedException)
		{
			throw new AccessDeniedException();
		}
	}

	/**
	 * The canon lets update and delete address the entity by filter, so the id arrives nullable and as
	 * a string. An absent one is refused by the request rules; what is left for here is an id that is
	 * present but names no entity - `0`, a negative number, a word. Answering that with "not found"
	 * would send the caller looking for a document that was never asked for.
	 */
	private function requireId(?string $id): int
	{
		$documentId = (int)$id;
		if ($documentId <= 0)
		{
			throw new RequestValidationException([new Error('id must be a positive integer', 'id')]);
		}

		return $documentId;
	}

	/**
	 * The cursor as the client sees it: three fields it passes back untouched. What they mean is the
	 * reader's business - the client is told only that the answer carries the next page start.
	 *
	 * @param array{stream: string, collectionId: int|null, id: int}|null $cursor
	 */
	private function buildCursor(?array $cursor): ?DocumentCursorDto
	{
		if ($cursor === null)
		{
			return null;
		}

		$dto = new DocumentCursorDto();
		$dto->stream = $cursor['stream'];
		$dto->collectionId = $cursor['collectionId'];
		$dto->id = $cursor['id'];

		return $dto;
	}

	private function getStateResolver(): DocumentStateResolver
	{
		return $this->stateResolver ??= new DocumentStateResolver();
	}

	private function getReader(): DocumentReader
	{
		// Shares the resolver with the actions, so a document resolved once in a request is not
		// resolved again to answer about it.
		return $this->reader ??= new DocumentReader($this->getStateResolver());
	}

	/**
	 * The answer of a write action, built from the document the command returned.
	 */
	private function answerWithSavedDocument(
		Result $result,
		int $documentId,
		bool $sharedAccess,
		?string $markdown = null,
	): GetResponse
	{
		$document = $result->getData()['document'] ?? null;
		if (!$document instanceof DocumentEntity)
		{
			throw new EntityNotFoundException($documentId);
		}

		return new GetResponse(
			$this->getDtoMapper()->mapOne(
				$this->getReader()->fromSavedDocument($document, $sharedAccess, $markdown),
			),
		);
	}

	private function mapCreateDomainException(\Throwable $cause, int $collectionId): never
	{
		if ($cause instanceof CollectionNotFoundException)
		{
			throw new EntityNotFoundException($collectionId);
		}

		if ($cause instanceof ParentDocumentMismatchException)
		{
			throw new InvalidParentException();
		}

		throw $cause;
	}

	/**
	 * A move fails in one way the other writes cannot: the destination may be shared wider than the
	 * branch being moved. That refusal keeps its own code so a client can say what is missing instead
	 * of repeating a request that will never pass. Everything else a move can hit - the main document,
	 * the archive, the recycle bin - answers as it does everywhere else.
	 */
	private function mapMoveDomainException(\Throwable $cause, int $documentId): never
	{
		if ($cause instanceof DomainMoveAccessEscalationException)
		{
			throw new MoveAccessEscalationException();
		}

		if ($cause instanceof MainDocumentOperationException)
		{
			throw new EntityNotFoundException($documentId);
		}

		$this->mapDocumentDomainException($cause, $documentId);
	}

	/**
	 * A restore fails in one way no other write does: the collection the document came from is gone,
	 * and the request has to name another one. That refusal keeps its own code, because "bad request"
	 * would leave the client guessing which field it is about.
	 */
	private function mapRestoreDomainException(\Throwable $cause, int $documentId): never
	{
		if ($cause instanceof DomainOrphanRestoreTargetRequiredException)
		{
			throw new RestoreTargetRequiredException();
		}

		$this->mapDocumentDomainException($cause, $documentId);
	}

	/**
	 * A restore refused before it wrote anything. Only one of those refusals is worth a code of its
	 * own, and it is the one a move has too: landing in a branch shared wider than what is being
	 * restored would hand that audience the whole branch. The rest - a row that has gone, a collection
	 * that went into the bin between the check and the call - are races with somebody else's write,
	 * and the document the caller asked about is no longer where they think it is.
	 */
	private function refuseFailedRestore(Result $result, int $documentId): never
	{
		if ($result->getErrorCollection()->getErrorByCode(PositionService::ERROR_MOVE_ACCESS_ESCALATION) !== null)
		{
			throw new MoveAccessEscalationException();
		}

		throw new EntityNotFoundException($documentId);
	}

	private function mapDocumentDomainException(\Throwable $cause, int $documentId): never
	{
		if ($cause instanceof DomainDocumentHasUnsavedChangesException)
		{
			throw new DocumentHasUnsavedChangesException();
		}

		if (
			$cause instanceof DocumentNotFoundException
			|| $cause instanceof DocumentArchivedException
			|| $cause instanceof DocumentInRecycleBinException
		)
		{
			throw new EntityNotFoundException($documentId);
		}

		if ($cause instanceof DomainAccessDeniedException)
		{
			throw new AccessDeniedException();
		}

		throw $cause;
	}

}
