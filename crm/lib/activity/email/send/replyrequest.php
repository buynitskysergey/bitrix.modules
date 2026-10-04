<?php

declare(strict_types=1);

namespace Bitrix\Crm\Activity\Email\Send;

final readonly class ReplyRequest
{
	/**
	 * @param list<mixed> $cc
	 * @param list<mixed> $bcc
	 */
	public function __construct(
		public int $userId,
		public int $parentActivityId,
		public string $body,
		public array $cc = [],
		public array $bcc = [],
		public ?string $rawFrom = null,
		public ?int $senderId = null,
		public ?int $mailboxId = null,
	)
	{
	}
}
