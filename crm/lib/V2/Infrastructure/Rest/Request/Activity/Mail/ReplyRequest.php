<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Activity\Mail;

use Bitrix\Rest\V3\Interaction\Request\Request;

class ReplyRequest extends Request
{
	public ?int $activityId = null;

	public ?string $body = null;

	public ?array $cc = null;

	public ?array $bcc = null;

	public ?string $from = null;

	public ?int $senderId = null;

	public ?int $mailboxId = null;
}
