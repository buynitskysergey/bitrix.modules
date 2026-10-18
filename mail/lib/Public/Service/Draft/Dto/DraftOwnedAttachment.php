<?php

declare(strict_types=1);

namespace Bitrix\Mail\Public\Service\Draft\Dto;

/**
 * One attachment copy a draft owns: the stored copy, the file the copy was made from, the Disk object
 * the copy was made of and the Disk object of the copy. The source ids are what a restored message body
 * still references, while the object id is both how a client names a restored attachment and how a
 * restored body references it inline. The source object also binds the copy to the converted large
 * attachment set it belongs to, which a consumer with its own send path has to exclude from the message.
 */
final class DraftOwnedAttachment
{
	public function __construct(
		public readonly int $fileId,
		public readonly int $sourceFileId,
		public readonly int $objectId,
		public readonly int $sourceObjectId = 0,
	)
	{
	}
}
