<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Controller;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Note\Infrastructure\Rest\V3\Controller\ActionFilter\NoteRestAccess;
use Bitrix\Note\Infrastructure\Rest\V3\Dto\DocumentItemDto;
use Bitrix\Note\Infrastructure\Rest\V3\Exceptions\DocumentHasUnsavedChangesException;
use Bitrix\Note\Infrastructure\Rest\V3\Exceptions\EmptyUpdateException;
use Bitrix\Note\Infrastructure\Rest\V3\Exceptions\InvalidParentException;
use Bitrix\Note\Infrastructure\Rest\V3\Exceptions\MarkdownTooLargeException;
use Bitrix\Note\Infrastructure\Rest\V3\Request\ArchiveDocumentRequest;
use Bitrix\Note\Infrastructure\Rest\V3\Request\UpdateDocumentRequest;
use Bitrix\Note\Internal\Exceptions\AccessDeniedException as DomainAccessDeniedException;
use Bitrix\Note\Internal\Exceptions\CollectionNotFoundException;
use Bitrix\Note\Internal\Exceptions\DocumentArchivedException;
use Bitrix\Note\Internal\Exceptions\DocumentHasUnsavedChangesException as DomainDocumentHasUnsavedChangesException;
use Bitrix\Note\Internal\Exceptions\DocumentInRecycleBinException;
use Bitrix\Note\Internal\Exceptions\DocumentNotFoundException;
use Bitrix\Note\Internal\Exceptions\ParentDocumentMismatchException;
use Bitrix\Note\Internal\Model\Document as DocumentEntity;
use Bitrix\Note\Internal\Model\DocumentTable;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Service\Analytics\AnalyticsDictionary;
use Bitrix\Note\Internal\Service\Analytics\AnalyticsService;
use Bitrix\Note\Public\Command\ArchiveDocumentCommand;
use Bitrix\Note\Public\Command\CreateDocumentCommand;
use Bitrix\Note\Public\Command\DeleteDocumentCommand;
use Bitrix\Note\Public\Command\OverwriteDocumentContentCommand;
use Bitrix\Note\Public\Command\UpdateDocumentCommand;
use Bitrix\Note\Public\Provider\DocumentProvider;
use Bitrix\Note\Public\Provider\Dto\DocumentReadDto;
use Bitrix\Note\Public\Provider\Param\Document\DocumentLimits;
use Bitrix\Note\Public\Service\AccessService;
use Bitrix\Main\Command\Exception\CommandException;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Attribute\RequiredGroup;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Exception\EntityNotFoundException;
use Bitrix\Rest\V3\Interaction\Request\AddRequest;
use Bitrix\Rest\V3\Interaction\Request\DeleteRequest;
use Bitrix\Rest\V3\Interaction\Request\GetRequest;
use Bitrix\Rest\V3\Interaction\Response\DeleteResponse;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\UpdateResponse;

#[DtoType(DocumentItemDto::class)]
class Document extends RestController
{
	protected function getDefaultPreFilters(): array
	{
		return [
			...parent::getDefaultPreFilters(),
			new NoteRestAccess(),
		];
	}

	public function getAction(GetRequest $request): GetResponse
	{
		$id = (int)$request->id;
		$this->assertNotMainDocument($id);
		$document = $this->readDocument($id);

		// view_document for the REST read; readDocument throws on missing/forbidden, so this fires only on success.
		AnalyticsService::documentViewed(true, AnalyticsDictionary::TYPE_REST);

		return new GetResponse($this->getDtoMapper()->mapOne($document));
	}

	public function addAction(AddRequest $request): GetResponse
	{
		/** @var DocumentItemDto $dto */
		$dto = $request->fields->convertToDto(RequiredGroup::Add->value);

		$collectionId = (int)$dto->collectionId;
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

		return new GetResponse($this->getDtoMapper()->mapOne($this->readDocument((int)$document->getId())));
	}

	public function updateAction(UpdateDocumentRequest $request): GetResponse
	{
		$id = (int)$request->id;
		$this->assertNotMainDocument($id);

		$items = $request->fields->getItems();
		$hasTitle = array_key_exists('title', $items);
		$hasMarkdown = array_key_exists('markdown', $items);
		if (!$hasTitle && !$hasMarkdown)
		{
			throw new EmptyUpdateException();
		}

		$ownership = (new DocumentProvider())->getOwnershipInfo($id);
		if ($ownership === null)
		{
			throw new EntityNotFoundException($id);
		}

		try
		{
			AccessService::assertCanEditDocument($id, $ownership['collectionId']);
		}
		catch (DomainAccessDeniedException)
		{
			throw new AccessDeniedException();
		}

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
				(new OverwriteDocumentContentCommand(
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

			return new GetResponse($this->getDtoMapper()->mapOne($this->readDocument($id)));
		}

		try
		{
			(new UpdateDocumentCommand(
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

		return new GetResponse($this->getDtoMapper()->mapOne($this->readDocument($id)));
	}

	public function archiveAction(ArchiveDocumentRequest $request): UpdateResponse
	{
		$this->assertNotMainDocument((int)$request->id);

		$ownership = (new DocumentProvider())->getOwnershipInfo($request->id);
		if ($ownership === null)
		{
			throw new EntityNotFoundException($request->id);
		}

		try
		{
			AccessService::assertCanEditCollection($ownership['collectionId']);
		}
		catch (DomainAccessDeniedException)
		{
			throw new AccessDeniedException();
		}

		$result = (new ArchiveDocumentCommand($request->id, $this->getCurrentUserId(), notifyInitiator: true, analyticsType: AnalyticsDictionary::TYPE_REST))->run();
		if (($result->getData()['success'] ?? false) !== true)
		{
			throw new EntityNotFoundException($request->id);
		}

		return new UpdateResponse(true);
	}

	public function deleteAction(DeleteRequest $request): DeleteResponse
	{
		$id = (int)$request->id;
		$this->assertNotMainDocument($id);

		$ownership = (new DocumentProvider())->getOwnershipInfo($id);
		if ($ownership === null)
		{
			throw new EntityNotFoundException($id);
		}

		try
		{
			// Destructive op: requires MANAGE on the owning collection, matching the engine path.
			AccessService::assertCanEditCollection($ownership['collectionId']);
		}
		catch (DomainAccessDeniedException)
		{
			throw new AccessDeniedException();
		}

		$result = (new DeleteDocumentCommand($id, $this->getCurrentUserId(), notifyInitiator: true, analyticsType: AnalyticsDictionary::TYPE_REST))->run();
		if (($result->getData()['success'] ?? false) !== true)
		{
			throw new EntityNotFoundException($id);
		}

		return new DeleteResponse(true);
	}

	/**
	 * External REST callers must not reach a collection's main document. It is exposed
	 * only through the collection's markdownDescription field, never as a standalone
	 * document. Treated as "not found" so existence is not leaked.
	 */
	private function assertNotMainDocument(int $id): void
	{
		if ($id > 0 && (new DocumentRepository())->isMainDocument($id))
		{
			throw new EntityNotFoundException($id);
		}
	}

	private function readDocument(int $id): DocumentReadDto
	{
		try
		{
			return (new DocumentProvider())->getForRead($id);
		}
		catch (DocumentNotFoundException)
		{
			throw new EntityNotFoundException($id);
		}
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

	private function getCurrentUserId(): int
	{
		return (int)CurrentUser::get()->getId();
	}
}
