<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Timeline\Activity\Email;

use Bitrix\Rest\V3\Interaction\Request\Request;

class ListRequest extends Request
{
	public ?int $id = null;

	public ?bool $isIncoming = null;

	public ?int $limit = null;

	public ?int $offset = null;
}
