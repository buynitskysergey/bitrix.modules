<?php

declare(strict_types=1);

namespace Bitrix\Crm\Activity\Email\Outgoing;

final readonly class SendRequest
{
	/**
	 * @param list<array{OWNER_TYPE_ID:int, OWNER_ID:int}> $bindings
	 * @param list<mixed> $to Raw or normalized TO recipient addresses.
	 * @param list<mixed> $cc Raw or normalized CC recipient addresses.
	 * @param list<mixed> $bcc Raw or normalized BCC recipient addresses.
	 */
	public function __construct(
		public int $userId,
		public int $mainOwnerTypeId,
		public int $mainOwnerId,
		public array $bindings,
		public ?int $parentActivityId,
		public string $subject,
		public string $body,
		public array $to,
		public array $cc = [],
		public array $bcc = [],
		public ?string $rawFrom = null,
		public ?int $senderId = null,
		public ?int $mailboxId = null,
	)
	{
	}
}
