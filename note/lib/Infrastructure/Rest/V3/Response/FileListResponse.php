<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Response;

use Bitrix\Note\Infrastructure\Rest\V3\Dto\FileCursorDto;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;

// Typed list envelope: {items: [...], nextCursor: {...}|null}, the same shape the other list
// methods answer with - the cursor inside it is the one of the attachment list. Schema is declared
// by FileListMethodProvider.
class FileListResponse extends ListResponse
{
	public function __construct(DtoCollection $items, public ?FileCursorDto $nextCursor = null)
	{
		parent::__construct($items);
	}
}
