<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Attachment;

use Bitrix\Mail\Helper\AttachmentHelper;

final class FileNameNormalizer
{
	public static function normalize(
		string $fileName,
		int $mailboxId,
		int $messageId,
		int $attachmentIndex,
		?string $contentType,
	): string
	{
		$fileName = trim($fileName);

		return $fileName !== ''
			? $fileName
			: AttachmentHelper::generateFileName($mailboxId, $messageId, $attachmentIndex, $contentType);
	}
}
