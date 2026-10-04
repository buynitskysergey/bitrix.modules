<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common;

use Bitrix\Rest\V3\Dto\Dto;

class CustomFieldDataDto extends Dto
{
	public mixed $value;
	public mixed $rawValue;
	public ?string $formattedValue;
	public ?array $extra;
}
