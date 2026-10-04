<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Signature\Template;

final class SignatureTemplateContext
{
	public function __construct(
		public readonly int $userId,
		public readonly int $mailboxId,
		public readonly string $senderEmail,
		public readonly string $senderName,
	)
	{
	}
}
