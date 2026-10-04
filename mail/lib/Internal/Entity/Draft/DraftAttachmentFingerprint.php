<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Entity\Draft;

final class DraftAttachmentFingerprint
{
	public static function fromRows(array $attachments): array
	{
		return array_map(
			static fn(array $attachment): array => [
				'sourceObjectId' => (int)$attachment['SOURCE_OBJECT_ID'],
				'sourceFileId' => (int)$attachment['SOURCE_FILE_ID'],
				'fileName' => (string)$attachment['FILE_NAME'],
				'fileSize' => (int)$attachment['FILE_SIZE'],
				'contentType' => $attachment['CONTENT_TYPE'] !== null
					? (string)$attachment['CONTENT_TYPE']
					: null,
				'sort' => (int)$attachment['SORT'],
			],
			$attachments,
		);
	}
}
