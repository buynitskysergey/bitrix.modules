<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common;

use Bitrix\Rest\V3\Dto\Dto;

class ItemDto extends Dto
{
	public int $id;
	public ?int $entityTypeId;
	public bool $canRead;
	public ?string $title;
	public ?string $url;
}
