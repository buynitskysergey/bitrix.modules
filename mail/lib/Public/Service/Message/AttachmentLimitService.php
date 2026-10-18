<?php

declare(strict_types=1);

namespace Bitrix\Mail\Public\Service\Message;

use Bitrix\Mail\Helper\Message;
use Bitrix\Mail\Internal\Service\LargeAttachment\AttachmentSizeGuard;

/**
 * How much a message may spend on attachments. A consumer that hosts its own compose form asks this instead
 * of reading the size option and repeating the transport overhead formula: both belong to the mail module,
 * and a second copy of either would let the two forms disagree about which set is too large to be sent.
 */
final class AttachmentLimitService
{
	/**
	 * The size a message may spend on attachments before the transport encodes them.
	 */
	public static function getMaxTotalSize(): int
	{
		return (int)Message::getMaxAttachedFilesSize();
	}

	/**
	 * The same limit as the sum of the raw sizes it allows, which is what a form shows to the sender.
	 */
	public static function getMaxTotalSizeAfterEncoding(): int
	{
		return (int)Message::getMaxAttachedFilesSizeAfterEncoding();
	}

	/**
	 * Whether the attachments left in the message no longer fit into it, which is what makes a set large.
	 *
	 * @param int $totalRawSize Sum of raw (pre-encoding) sizes of the attachments, in bytes.
	 */
	public static function exceedsLimit(int $totalRawSize): bool
	{
		return AttachmentSizeGuard::exceedsMailLimit($totalRawSize);
	}
}
