<?php

declare(strict_types=1);

namespace Bitrix\Crm\Activity\Email\Send;

final readonly class Request
{
	/**
	 * @param list<mixed> $to
	 * @param list<mixed> $cc
	 * @param list<mixed> $bcc
	 */
	public function __construct(
		public int $userId,
		public int $entityTypeId,
		public int $entityId,
		public array $to,
		public array $cc,
		public array $bcc,
		public string $subject,
		public string $body,
		public ?string $rawFrom = null,
		public ?int $senderId = null,
		public ?int $mailboxId = null,
	)
	{
	}
}
