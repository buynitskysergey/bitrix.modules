<?php

namespace Bitrix\Main\Infrastructure\Rest\Request\User;

use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Rest\V3\Interaction\Request\ListRequest;
use Bitrix\Rest\V3\Structure\Filtering\Attribute\FilterRequired;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;

class HistoryListRequest extends ListRequest
{
	#[FilterRequired(['userId'])]
	#[NotEmpty]
	public ?FilterStructure $filter = null;
}
