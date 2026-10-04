<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\CustomFieldData;

use Bitrix\Rest\V3\Dto\Dto;

class CrmStatusExtraDto extends Dto
{
	public string $id;
	public ?string $name = null;
	public ?int $sort = null;
}
