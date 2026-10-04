<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Dictionary\AddressType;

use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\SelectStructure;

final class GetRequest extends Request
{
	#[NotEmpty]
	#[PositiveNumber]
	public int $id;

	public ?SelectStructure $select = null;
}
