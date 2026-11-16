<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Dto;

use Bitrix\Rest\V3\Dto\Dto;

// Keyset pagination cursor of the document list: the stream of visibility it belongs to and the
// place it stopped at inside that stream. Kept apart from CursorDto - note.collection.list orders by
// (POSITION, ID) and its cursor means something else. Built by hand and only serialized, so it needs
// no mapper.
class DocumentCursorDto extends Dto
{
	public ?string $stream;
	public ?int $collectionId;
	public ?int $id;
}
