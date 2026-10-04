<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common;

use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Dto\Dto;

class CrmTrackingDto extends Dto
{
	#[Editable]
	public ?UtmDto $utm;
}
