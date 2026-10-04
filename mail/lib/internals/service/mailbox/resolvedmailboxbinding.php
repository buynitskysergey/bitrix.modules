<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internals\Service\Mailbox;

final class ResolvedMailboxBinding
{
	public function __construct(
		public readonly int $mailboxId,
		public readonly string $currentEmail,
		public readonly bool $isAlias,
	)
	{
	}
}
