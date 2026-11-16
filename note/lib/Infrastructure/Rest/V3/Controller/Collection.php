<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Controller;

use Bitrix\Main\Error;
use Bitrix\Note\Infrastructure\Rest\V3\Controller\ActionFilter\NoteRestAccess;
use Bitrix\Note\Infrastructure\Rest\V3\Dto\CollectionItemDto;
use Bitrix\Note\Infrastructure\Rest\V3\Dto\CursorDto;
use Bitrix\Note\Infrastructure\Rest\V3\Read\CollectionReadQuery;
use Bitrix\Note\Infrastructure\Rest\V3\Read\CollectionReader;
use Bitrix\Note\Infrastructure\Rest\V3\Read\StateFilter;
use Bitrix\Note\Infrastructure\Rest\V3\Response\CollectionListResponse;
use Bitrix\Note\Infrastructure\Rest\V3\Structure\CollectionPaginationStructure;
use Bitrix\Note\Infrastructure\Rest\V3\Exceptions\CollectionInRecycleBinException;
use Bitrix\Note\Infrastructure\Rest\V3\Exceptions\CollectionRestoreRefusedException;
use Bitrix\Note\Infrastructure\Rest\V3\Exceptions\CollectionUnderImportException;
use Bitrix\Note\Internal\Access\Service\DocumentAccessService;
use Bitrix\Note\Internal\Exceptions\AccessDeniedException as DomainAccessDeniedException;
use Bitrix\Note\Internal\Model\Collection as CollectionEntity;
use Bitrix\Note\Internal\Service\Analytics\AnalyticsDictionary;
use Bitrix\Note\Internal\Service\Analytics\AnalyticsService;
use Bitrix\Note\Internal\Service\Analytics\AnalyticsStats;
use Bitrix\Note\Internal\Service\Collection\CollectionRestoreService;
use Bitrix\Note\Infrastructure\Rest\V3\Request\ArchiveCollectionRequest;
use Bitrix\Note\Infrastructure\Rest\V3\Request\ListCollectionsRequest;
use Bitrix\Note\Infrastructure\Rest\V3\Request\MoveCollectionRequest;
use Bitrix\Note\Infrastructure\Rest\V3\Write\PositionSlotResolver;
use Bitrix\Note\Public\Command\ArchiveCollectionCommand;
use Bitrix\Note\Public\Command\CreateCollectionCommand;
use Bitrix\Note\Public\Command\DeleteCollectionCommand;
use Bitrix\Note\Public\Command\MoveCollectionCommand;
use Bitrix\Note\Public\Command\RestoreCollectionFromRecycleBinCommand;
use Bitrix\Note\Public\Command\UpdateCollectionCommand;
use Bitrix\Note\Public\Provider\CollectionProvider;
use Bitrix\Note\Public\Service\AccessService;
use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Attribute\Idempotent;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Attribute\RequiredGroup;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Exception\EntityNotFoundException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Interaction\Request\AddRequest;
use Bitrix\Rest\V3\Interaction\Request\DeleteRequest;
use Bitrix\Rest\V3\Interaction\Request\GetRequest;
use Bitrix\Rest\V3\Interaction\Request\UpdateRequest;
use Bitrix\Rest\V3\Interaction\Response\DeleteResponse;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\UpdateResponse;

#[DtoType(CollectionItemDto::class)]
class Collection extends RestController
{
	use CurrentUserAware;

	protected function getDefaultPreFilters(): array
	{
		return [
			...parent::getDefaultPreFilters(),
			new NoteRestAccess(),
		];
	}

	public function listAction(ListCollectionsRequest $request): CollectionListResponse
	{
		$pagination = $request->pagination ?? new CollectionPaginationStructure();
		$states = StateFilter::parse($request->filter);

		$reader = new CollectionReader();
		$query = new CollectionReadQuery(
			select: $request->select?->getStructuredList() ?? [],
			visibleIds: $reader->visibleTo($this->getCurrentUserId(), $states),
			limit: $pagination->getLimit(),
			cursor: $pagination->getAfterCursor(),
			states: $states,
		);

		$result = $reader->list($query);
		$items = $this->getDtoMapper()->mapCollection($result->items, $query->select);

		return new CollectionListResponse($items, $this->buildCursor($result->nextCursor));
	}

	#[Title('One collection by its id')]
	#[Description(
		'Reads a single collection, archived and deleted ones included: a collection in the recycle bin '
		. 'answers with `state: trashed` to whoever may dispose of it there - the right to moderate it - '
		. 'and as a missing one to everybody else, the same way a collection the caller may not open is '
		. 'answered, so that its very existence is not disclosed. `markdownDescription` carries the text '
		. 'of the collection\'s main document - the description shown at the top of its knowledge base.'
	)]
	public function getAction(GetRequest $request): GetResponse
	{
		$id = (int)$request->id;
		$collection = $this->requireVisibleCollection($id);

		// view_collection for the REST read; only after the collection is confirmed visible and present.
		AnalyticsService::collectionViewed(true, AnalyticsDictionary::TYPE_REST);

		return new GetResponse($this->getDtoMapper()->mapOne($collection, $request->select?->getStructuredList() ?? []));
	}

	#[Title('Create a collection')]
	#[Description(
		'Creates a collection whose sole moderator is the caller: nobody else sees it until its access '
		. 'is given away, which REST does not do yet. Requires the portal-wide right to create '
		. 'collections; without it the answer is a refusal rather than an empty result.'
	)]
	public function addAction(AddRequest $request): GetResponse
	{
		try
		{
			AccessService::assertCanCreateCollections();
		}
		catch (DomainAccessDeniedException)
		{
			throw new AccessDeniedException();
		}

		/** @var CollectionItemDto $dto */
		$dto = $request->fields->convertToDto(RequiredGroup::Add->value);

		$result = (new CreateCollectionCommand(
			(string)$dto->name,
			$this->getCurrentUserId(),
			$dto->position ?? 0,
		))->run();

		$collection = $result->getData()['collection'] ?? null;
		if (!$collection instanceof CollectionEntity)
		{
			throw new EntityNotFoundException(0);
		}

		// REST creator is always the sole moderator (admin=1); no import context => no importType.
		AnalyticsService::collectionCreated(
			true,
			AnalyticsStats::buildCollectionStats(1, 0, 0),
			AnalyticsDictionary::TYPE_REST,
		);

		return new GetResponse($this->getDtoMapper()->mapOne($collection));
	}

	#[Title('Rename a collection')]
	#[Description(
		'Renames a collection. The name is the only editable field: position, access and archive state '
		. 'are moved by their own methods. An archived collection is read-only, and so is one in the '
		. 'recycle bin, so a write into either is refused - read it and show the caller why.'
	)]
	public function updateAction(UpdateRequest $request): GetResponse
	{
		$id = $this->requireId($request->id);
		$this->assertWritable($this->requireVisibleCollection($id));
		try
		{
			AccessService::assertCanEditCollection($id);
		}
		catch (DomainAccessDeniedException)
		{
			throw new AccessDeniedException();
		}

		/** @var CollectionItemDto $dto */
		$dto = $request->fields->convertToDto(RequiredGroup::Update->value);

		$result = (new UpdateCollectionCommand(
			$id,
			(string)$dto->name,
			$this->getCurrentUserId(),
			notifyInitiator: true,
		))->run();

		$collection = $result->getData()['collection'] ?? null;
		if (!$collection instanceof CollectionEntity)
		{
			throw new EntityNotFoundException($id);
		}

		return new GetResponse($this->getDtoMapper()->mapOne($collection));
	}

	#[Idempotent]
	#[Title('Change where a collection sits in the list')]
	#[Description(
		'Moves the collection to another place in the list of collections. The order is one for the '
		. 'whole portal - the same order every person sees in the sidebar - so this changes it for '
		. 'everybody, and it takes the portal-wide right to create collections, exactly as the sidebar '
		. 'does. `position` is the sort weight the listings answer with: pass a number between the '
		. 'weights of two collections to land between them, and omit it to land last. The weight '
		. 'actually stored is recomputed from the neighbours - it may differ from the one asked for - so '
		. 'the answer carries the collection as it was saved. An archived collection is not reordered, '
		. 'nor is one in the recycle bin: both are out of the list to begin with.'
	)]
	public function moveAction(MoveCollectionRequest $request): GetResponse
	{
		$id = $request->id;
		$this->assertWritable($this->requireVisibleCollection($id));
		try
		{
			// The list is portal-wide, and so is the right that guards its order: the web asks for the
			// same one rather than for rights on the collection being dragged.
			AccessService::assertCanCreateCollections();
		}
		catch (DomainAccessDeniedException)
		{
			throw new AccessDeniedException();
		}

		(new MoveCollectionCommand(
			$id,
			(new PositionSlotResolver())->forCollection($id, $request->position),
			$this->getCurrentUserId(),
		))->run();

		$moved = (new CollectionProvider())->getById($id);
		if ($moved === null)
		{
			throw new EntityNotFoundException($id);
		}

		return new GetResponse($this->getDtoMapper()->mapOne($moved));
	}

	#[Idempotent]
	#[Title('Send a collection to the archive')]
	#[Description(
		'Archives the collection together with everything inside it. An archived collection still '
		. 'answers reads and still comes back from note.collection.get; what it refuses is every '
		. 'write, and it drops out of note.collection.list. A collection that is already in the archive '
		. 'is answered as missing, the same refusal an already archived document gets: the archive is a '
		. 'state to enter, not a flag to set again. A collection in the recycle bin is not archived at '
		. 'all - it has to be restored first. Requires the right to moderate the collection.'
	)]
	public function archiveAction(ArchiveCollectionRequest $request): UpdateResponse
	{
		$id = (int)$request->id;
		// Not assertWritable: a collection already in the archive is answered as missing, which is
		// this action's own refusal and not the read-only one.
		$this->assertNotTrashed($this->requireVisibleCollection($id));
		try
		{
			AccessService::assertCanModerateCollection($id);
		}
		catch (DomainAccessDeniedException)
		{
			throw new AccessDeniedException();
		}

		$result = (new ArchiveCollectionCommand(
			$id,
			$this->getCurrentUserId(),
			analyticsType: AnalyticsDictionary::TYPE_REST,
		))->run();

		if (($result->getData()['success'] ?? false) !== true)
		{
			throw new EntityNotFoundException($id);
		}

		return new UpdateResponse(true);
	}

	#[Idempotent]
	#[Title('Bring a collection back from the archive')]
	#[Description(
		'Takes the collection out of the archive with everything inside it, and it appears in '
		. 'note.collection.list again. A collection that is not in the archive is answered as missing, '
		. 'the mirror of what archiving an already archived one answers: the archive is a state to '
		. 'enter and to leave, not a flag to set and clear. A collection lying in the recycle bin is '
		. 'not unarchived either - out of the bin it would still be out of reach, so the way back is '
		. 'note.collection.restore first. Requires the right to moderate the collection.'
	)]
	public function unarchiveAction(ArchiveCollectionRequest $request): UpdateResponse
	{
		$id = (int)$request->id;
		$collection = $this->requireVisibleCollection($id);
		// A base can be archived and lie in the bin at once, and one action must not lift both
		// states: the way out of the bin is its own, exactly as it is in the web interface.
		$this->assertNotTrashed($collection);
		try
		{
			AccessService::assertCanModerateCollection($id);
		}
		catch (DomainAccessDeniedException)
		{
			throw new AccessDeniedException();
		}

		if (!$collection->getIsArchived())
		{
			throw new EntityNotFoundException($id);
		}

		$result = (new CollectionRestoreService())->restore($id, $this->getCurrentUserId());
		if (!(($result->getData()['collection'] ?? null) instanceof CollectionEntity))
		{
			throw new EntityNotFoundException($id);
		}

		return new UpdateResponse(true);
	}

	#[Title('Move a collection to the recycle bin')]
	#[Description(
		'Moves the collection to the recycle bin together with everything inside it, its description '
		. 'and its access list - nothing is erased, so this is not a way to destroy the content. A '
		. 'collection in the bin keeps answering note.collection.get with `state: trashed` for whoever '
		. 'may moderate it; restoring it and removing it for good are actions of the web interface. '
		. 'Deleting the same collection twice is not an error: the second call answers with the state '
		. 'the caller asked for. An archived collection may be deleted too, so an archive cannot turn '
		. 'into rubbish nobody is able to remove. A collection in the middle of an import is refused '
		. 'until the import ends. Requires the right to moderate the collection.'
	)]
	public function deleteAction(DeleteRequest $request): DeleteResponse
	{
		$id = $this->requireId($request->id);
		// Archived on purpose not refused here: the web forbids it, REST does not, so an archive cannot
		// turn into rubbish nobody is able to remove. A collection already in the bin needs no special
		// case any more - it is visible to the very caller who may delete it, and the command answers
		// a repeated delete with success.
		$this->requireVisibleCollection($id);
		try
		{
			AccessService::assertCanModerateCollection($id);
		}
		catch (DomainAccessDeniedException)
		{
			throw new AccessDeniedException();
		}

		$result = (new DeleteCollectionCommand(
			$id,
			$this->getCurrentUserId(),
			analyticsType: AnalyticsDictionary::TYPE_REST,
		))->run();

		$data = $result->getData();
		if (($data['errorCode'] ?? null) === 'COLLECTION_UNDER_IMPORT')
		{
			throw new CollectionUnderImportException();
		}

		if (($data['success'] ?? false) !== true)
		{
			throw new EntityNotFoundException($id);
		}

		return new DeleteResponse(true);
	}

	#[Idempotent]
	#[Title('Bring a collection back from the recycle bin')]
	#[Description(
		'Takes the collection out of the recycle bin with the tree it had when it was deleted - the '
		. 'documents that went there with it come back where they were, and the ones thrown away '
		. 'earlier stay in the bin. A collection that is already out of the bin is answered with the '
		. 'state the caller asked for rather than with an error, so a repeated call is safe. Three '
		. 'refusals have codes of their own, because they are not the same news: '
		. '`COLLECTION_RESTORE_IN_PROGRESS` means another restore of this collection is running and '
		. 'the call is worth repeating, while `COLLECTION_RETENTION_EXPIRED` (the bin has kept it '
		. 'longer than the retention allows) and `COLLECTION_PURGE_STARTED` (its destruction for good '
		. 'has begun) mean the collection is not coming back at all. Requires the right to moderate '
		. 'the collection - the same right that lets the caller see it in the bin in the first place.'
	)]
	public function restoreAction(ArchiveCollectionRequest $request): UpdateResponse
	{
		$id = (int)$request->id;
		$collection = $this->requireVisibleCollection($id);

		// A base in the bin is visible to whoever may dispose of it there and to nobody else, so the
		// gate is already passed. One out of the bin is a repeated call, and the right that would
		// have taken it out is asked for here instead - the level is the same one.
		if ($collection->getTrashedAt() === null)
		{
			try
			{
				AccessService::assertCanModerateCollection($id);
			}
			catch (DomainAccessDeniedException)
			{
				throw new AccessDeniedException();
			}
		}

		$data = (new RestoreCollectionFromRecycleBinCommand($id, $this->getCurrentUserId()))
			->run()
			->getData()
		;

		$errorCode = (string)($data['errorCode'] ?? '');
		if (CollectionRestoreRefusedException::speaksFor($errorCode))
		{
			throw new CollectionRestoreRefusedException($errorCode);
		}

		if (($data['success'] ?? false) !== true)
		{
			throw new EntityNotFoundException($id);
		}

		return new UpdateResponse(true);
	}

	/**
	 * The canon lets update and delete address the entity by filter, so the id arrives nullable and as
	 * a string. An absent one is refused by the request rules; what is left for here is an id that is
	 * present but names no entity - `0`, a negative number, a word. Answering that with "no access"
	 * would send the caller asking their administrator about a collection that was never asked for.
	 */
	private function requireId(?string $id): int
	{
		$collectionId = (int)$id;
		if ($collectionId <= 0)
		{
			throw new RequestValidationException([new Error('id must be a positive integer', 'id')]);
		}

		return $collectionId;
	}


	/**
	 * "Invisible is non-existent": a caller who is not let into a collection is told the same thing as
	 * one who named an id that was never there. Answering 403 here would confirm the collection exists
	 * to somebody who is not supposed to know even that.
	 *
	 * A collection in the recycle bin is visible to whoever disposes of it there, and to nobody else:
	 * the bin is governed by the single right to moderate the base as a whole, so a caller who could
	 * merely read the base while it was alive is told it is gone.
	 */
	private function requireVisibleCollection(int $id): CollectionEntity
	{
		$collection = (new CollectionProvider())->getById($id);
		if ($collection === null)
		{
			throw new EntityNotFoundException($id);
		}

		$visible = $collection->getTrashedAt() === null
			? AccessService::canViewCollection($id)
			: DocumentAccessService::canManageCollectionInRecycleBin($this->getCurrentUserId(), $id)
		;

		if (!$visible)
		{
			throw new EntityNotFoundException($id);
		}

		return $collection;
	}

	/**
	 * An archived collection is read-only, as it is in the web interface, and so is one lying in the
	 * recycle bin. Reading either stays allowed, so the client can show why the writes are refused.
	 */
	private function assertWritable(CollectionEntity $collection): void
	{
		$this->assertNotTrashed($collection);

		if ($collection->getIsArchived())
		{
			throw new AccessDeniedException();
		}
	}

	/**
	 * A collection in the recycle bin is read-only too, and the refusal belongs here rather than in
	 * the domain command behind the action: the command throws an exception the wire cannot name,
	 * and the caller would read an internal error where a refusal was meant.
	 *
	 * The refusal carries a code of its own: the caller is short of no right - a base in the bin is
	 * visible to whoever may dispose of it, and that is exactly who runs into this - so "no access"
	 * would send them asking for a right they already hold instead of taking the base out first.
	 */
	private function assertNotTrashed(CollectionEntity $collection): void
	{
		if ($collection->getTrashedAt() !== null)
		{
			throw new CollectionInRecycleBinException();
		}
	}

	private function buildCursor(?array $cursor): ?CursorDto
	{
		if ($cursor === null)
		{
			return null;
		}

		$dto = new CursorDto();
		$dto->position = (int)$cursor['position'];
		$dto->id = (int)$cursor['id'];

		return $dto;
	}
}
