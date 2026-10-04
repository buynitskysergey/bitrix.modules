<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Timeline\Activity\Email;

use Bitrix\Rest\V3\Interaction\Request\Request;

class SendRequest extends Request
{
	public ?int $id = null;

	public ?array $to = null;

	public ?array $cc = null;

	public ?array $bcc = null;

	public ?string $subject = null;

	public ?string $body = null;

	public ?string $from = null;

	public ?int $senderId = null;

	public ?int $mailboxId = null;
}
