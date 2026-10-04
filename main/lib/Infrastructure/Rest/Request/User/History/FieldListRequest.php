<?php

namespace Bitrix\Main\Infrastructure\Rest\Request\User\History;

use Bitrix\Rest\V3\Interaction\Request\ListRequest;
use Bitrix\Rest\V3\Structure\Filtering\Attribute\FilterRequired;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;

class FieldListRequest extends ListRequest
{
	#[FilterRequired(['historyId'])]
	public ?FilterStructure $filter = null;
}
