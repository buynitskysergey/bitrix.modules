<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Messenger\Storage;

use Bitrix\Main\Messenger\Internals\Storage\Db\Model\MessengerMessageTable;

final class VibecodeMessengerMessageTable extends MessengerMessageTable
{
	public static function getTableName(): string
	{
		return 'b_vibecodeconnector_messenger_message';
	}
}
