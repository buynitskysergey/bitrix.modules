<?php

namespace Bitrix\Main\Infrastructure\Rest\Request;

use Bitrix\Rest\V3\Interaction\Request\ListRequest;

class SmileListRequest extends ListRequest
{
	public ?bool $fullTypings = null;
}
