<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Activity\Mail;

use Bitrix\Rest\V3\Interaction\Request\Request;

class GetContentRequest extends Request
{
	public ?int $activityId = null;
}
