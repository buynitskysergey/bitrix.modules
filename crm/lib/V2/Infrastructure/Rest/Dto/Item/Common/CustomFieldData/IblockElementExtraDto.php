<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\CustomFieldData;

use Bitrix\Rest\V3\Dto\Dto;

class IblockElementExtraDto extends Dto
{
	public int $id;
	public ?string $name = null;
}
