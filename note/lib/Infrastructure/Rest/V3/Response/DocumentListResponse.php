<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Response;

use Bitrix\Note\Infrastructure\Rest\V3\Dto\DocumentCursorDto;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;

// Typed list envelope: {items: [...], nextCursor: {...}|null}, the same shape note.collection.list
// answers with - the cursor inside it is the one of the document list. Schema is declared by
// DocumentListMethodProvider.
class DocumentListResponse extends ListResponse
{
	public function __construct(DtoCollection $items, public ?DocumentCursorDto $nextCursor = null)
	{
		parent::__construct($items);
	}
}
