<?php

declare(strict_types=1);

namespace Bitrix\Mail\Public\Service\LargeAttachment;

use Bitrix\Mail\Internal\Service\LargeAttachment\LargeAttachmentService;
use Bitrix\Main\Result;

/**
 * The service marker that ties a stored outgoing message to the sets it sent as service links. A consumer
 * that stores the outgoing copy itself registers the marker through this service, so the meaning of the
 * marker and the storage behind it stay inside the mail module.
 */
final class MessageMarkerService
{
	/**
	 * @param int[] $externalLinkIds Identifiers of the sets the message carries as service links.
	 */
	public static function registerForMessage(int $messageId, array $externalLinkIds): Result
	{
		return LargeAttachmentService::registerMessageMarkers($messageId, $externalLinkIds);
	}
}
