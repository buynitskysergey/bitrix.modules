<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Controller\Document;

use Bitrix\Note\Internal\Exceptions\AccessDeniedException as DomainAccessDeniedException;
use Bitrix\Note\Internal\Exceptions\CollectionNotFoundException;
use Bitrix\Note\Internal\Exceptions\CollectionTreeTooLargeException;
use Bitrix\Note\Public\Provider\TreeProvider;
use Bitrix\Note\Infrastructure\Rest\V3\Controller\ActionFilter\NoteRestAccess;
use Bitrix\Note\Infrastructure\Rest\V3\Dto\DocumentTreeItemDto;
use Bitrix\Note\Infrastructure\Rest\V3\Exceptions\TreeTooLargeException;
use Bitrix\Note\Infrastructure\Rest\V3\Request\GetDocumentTreeRequest;
use Bitrix\Note\Infrastructure\Rest\V3\Response\DocumentTreeListResponse;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Exception\EntityNotFoundException;

// DtoType publishes DocumentTreeItemDto as an OpenAPI component; its itemless recursive `children`
// is overridden by DocumentTreeNodeSchemaProvider (.settings.php) into a self-$ref so it renders.
#[DtoType(DocumentTreeItemDto::class)]
class Tree extends RestController
{

	protected function getDefaultPreFilters(): array
	{
		return [
			...parent::getDefaultPreFilters(),
			new NoteRestAccess(),
		];
	}

	public function listAction(GetDocumentTreeRequest $request): DocumentTreeListResponse
	{
		try
		{
			$nodes = (new TreeProvider())->getWholeAccessibleTree($request->collectionId);
		}
		catch (CollectionNotFoundException)
		{
			throw new EntityNotFoundException($request->collectionId);
		}
		catch (DomainAccessDeniedException)
		{
			// A collection the caller is not let into is answered as a missing one, exactly as
			// note.collection.get answers it.
			throw new EntityNotFoundException($request->collectionId);
		}
		catch (CollectionTreeTooLargeException $exception)
		{
			throw new TreeTooLargeException($exception->getBudget(), $exception);
		}

		$items = new DtoCollection(DocumentTreeItemDto::class);
		foreach ($nodes as $node)
		{
			$items->add($this->toTreeDto($node));
		}

		return new DocumentTreeListResponse($items);
	}

	// Wrap the root node in a typed DTO; nested children stay as the arrays TreeProvider built
	// and serialize verbatim, so the nested shape is preserved without a recursive mapper.
	private function toTreeDto(array $node): DocumentTreeItemDto
	{
		$dto = new DocumentTreeItemDto();
		$dto->id = (int)$node['id'];
		$dto->collectionId = (int)$node['collectionId'];
		$dto->parentId = $node['parentId'] !== null ? (int)$node['parentId'] : null;
		$dto->title = (string)$node['title'];
		$dto->position = (int)$node['position'];
		$dto->children = $node['children'];

		return $dto;
	}
}
