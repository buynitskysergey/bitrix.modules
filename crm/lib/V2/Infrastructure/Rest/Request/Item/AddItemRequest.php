<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Item;

use Bitrix\Rest\V3\Structure\FieldsStructure;

class AddItemRequest extends AbstractItemRequest
{
	public FieldsStructure $fields;
}
