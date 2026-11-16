<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Dto;

use Bitrix\Rest\V3\Dto\Dto;

// Keyset pagination cursor of the attachment list: the FILE_ID the previous page stopped at. The
// primary key of the link is the whole cursor - the list belongs to one document and is ordered by
// nothing else. Built by hand and only serialized, so it needs no mapper.
class FileCursorDto extends Dto
{
	public ?int $id;
}
