<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Item;

use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\SelectStructure;
use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\PositiveNumber;

class GetItemRequest extends AbstractItemRequest
{
	#[NotEmpty]
	#[PositiveNumber]
	public int $id;

	public ?SelectStructure $select = null;
}
