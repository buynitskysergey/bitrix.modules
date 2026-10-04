<?php

declare(strict_types=1);

namespace Bitrix\Mail\Public\Service\Draft\Dto;

/**
 * One attachment copy a draft owns: the stored copy, the file the copy was made from and the Disk
 * object of the copy. The source id is what a restored message body still references, while the object
 * id is both how a client names a restored attachment and how a restored body references it inline.
 */
final class DraftOwnedAttachment
{
	public function __construct(
		public readonly int $fileId,
		public readonly int $sourceFileId,
		public readonly int $objectId,
	)
	{
	}
}
