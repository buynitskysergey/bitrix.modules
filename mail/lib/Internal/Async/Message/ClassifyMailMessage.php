<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Async\Message;

use Bitrix\Main\Messenger\Entity\AbstractMessage;

/**
 * Carries only the identifiers: the text is built when the message is processed, so a
 * capped 16K body never travels through the queue and never goes stale in it.
 */
class ClassifyMailMessage extends AbstractMessage
{
	public function __construct(
		public readonly int $mailboxId,
		public readonly int $messageId,
	)
	{
	}
}
