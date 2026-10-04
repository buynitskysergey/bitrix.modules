<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Async\Model;

use Bitrix\Main\Messenger\Internals\Storage\Db\Model\MessengerMessageTable;

/**
 * Queue storage of the module, see {@see \Bitrix\Main\Messenger\Internals\Broker\DbBroker}.
 *
 * The classification queue is the one whose volume follows the incoming mail flow, so it grows
 * here and not in the shared table of the portal. Fetch lock and requeue marker are keyed by
 * table name too, so its window is read apart from the other queues.
 */
class MessengerTable extends MessengerMessageTable
{
	public static function getTableName(): string
	{
		return 'b_mail_messenger_message';
	}

	/**
	 * Nothing here is read through a cached query, while every write on the shared table pays
	 * Entity::cleanCache(): 3.70 ms per message against 0.12 ms of the insert itself.
	 */
	public static function isCacheable(): bool
	{
		return false;
	}
}
