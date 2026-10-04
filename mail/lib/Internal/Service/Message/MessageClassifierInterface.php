<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Message;

/**
 * The role the mail module needs to have a letter classified. Which engine stands behind it is known to
 * lib/integration/ai alone.
 */
interface MessageClassifierInterface
{
	/**
	 * The labels arrive later and by another way, so the outcome only tells whether the letter was taken
	 * and whether sending it again is worth anything.
	 */
	public function scheduleClassification(int $mailboxId, int $messageId, string $text): ClassificationOutcome;
}
