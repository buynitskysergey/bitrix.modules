<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\MailService;

final class MailboxSwitchAuthorization
{
	public function __construct(
		private readonly string $operationId,
		private readonly int $mailboxId,
	)
	{
	}

	public function operationId(): string
	{
		return $this->operationId;
	}

	public function mailboxId(): int
	{
		return $this->mailboxId;
	}
}
