<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Item;

use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Rest\V3\Structure\FieldsStructure;

class UpdateItemRequest extends AbstractItemRequest
{
	#[NotEmpty]
	#[PositiveNumber]
	public int $id;

	public FieldsStructure $fields;
}
