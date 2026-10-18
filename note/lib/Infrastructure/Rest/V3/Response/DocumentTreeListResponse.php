<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Response;

use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;

// Typed tree envelope: {items: [root nodes with nested children]}. The answer carries the collection
// whole or refuses it, so there is nothing to page through and no cursor to carry.
// Nodes are DocumentTreeItemDto; children stay as nested arrays and serialize verbatim.
// Schema is declared by DocumentTreeListMethodProvider.
class DocumentTreeListResponse extends ListResponse
{
}
