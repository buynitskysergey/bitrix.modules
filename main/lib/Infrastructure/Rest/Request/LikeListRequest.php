<?php

namespace Bitrix\Main\Infrastructure\Rest\Request;

use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Rest\V3\Interaction\Request\ListRequest;
use Bitrix\Rest\V3\Structure\Filtering\Attribute\FilterRequired;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;

class LikeListRequest extends ListRequest
{
	#[FilterRequired(['entityTypeId', 'entityId'])]
	#[NotEmpty]
	public ?FilterStructure $filter = null;
}
