<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common;

use Bitrix\Main\Type\Date;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Dto\Dto;

class BeginCloseDatesDto extends Dto
{
	#[Editable]
	#[Filterable]
	#[Sortable]
	public Date $beginTime;

	#[Editable]
	#[Filterable]
	#[Sortable]
	public Date $closeTime;
}
