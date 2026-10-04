<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\Dictionary;

use Bitrix\Rest\V3\Dto\Dto;

class PersonTypeDto extends Dto
{
	public string $id;
	public ?string $name;
}
