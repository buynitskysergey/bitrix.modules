<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Field;

use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\SelectStructure;
use Bitrix\Rest\V3\Interaction\Request\Request;

class ListRequest extends Request
{
	public ?SelectStructure $select = null;
}
