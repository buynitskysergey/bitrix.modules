<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper\Message;

use Bitrix\Main\SystemException;

final class MailboxMigrationActionException extends SystemException
{
	public function __construct(
		public readonly string $errorCode,
	)
	{
		parent::__construct('The mailbox action is unavailable while its migration status is active or unavailable.');
	}
}
