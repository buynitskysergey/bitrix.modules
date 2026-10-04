<?php


namespace Bitrix\Rest\Internal\Service\Messenger\DeferredBatch;

use Bitrix\Main\Messenger\Entity\AbstractMessage;

/**
 * Publishes deferred execution of a registered batch command.
 *
 * @see Receiver::process()
 */
final class Message extends AbstractMessage
{
	public const QUEUE_ID = 'rest.deferred_batch';

	public function __construct(public readonly int $commandId)
	{
	}
}

