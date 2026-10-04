<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\Dictionary;

use Bitrix\Rest\V3\Dto\Dto;

class CategoryDto extends Dto
{
	public int $id;
	public ?string $name;
	public ?int $sort;
	public ?bool $isDefault;
}
