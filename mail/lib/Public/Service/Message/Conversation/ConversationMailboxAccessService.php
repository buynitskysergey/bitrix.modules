<?php

declare(strict_types=1);

namespace Bitrix\Mail\Public\Service\Message\Conversation;

use Bitrix\Mail\Helper\MailboxAccess;
use Bitrix\Mail\MailboxTable;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class ConversationMailboxAccessService
{
	public const ERROR_ACCESS_DENIED = 'ACCESS_DENIED';
	public const ERROR_MAILBOX_UNAVAILABLE = 'MAILBOX_UNAVAILABLE';

	public function check(int $mailboxId, int $userId): Result
	{
		$result = new Result();
		if ($userId <= 0 || !MailboxAccess::hasUserAccessToMailbox($mailboxId, $userId, true))
		{
			return $result->addError(
				new Error('Access to the mailbox is denied.', self::ERROR_ACCESS_DENIED),
			);
		}

		$isAvailable = MailboxTable::query()
			->setSelect(['ID'])
			->where('ID', $mailboxId)
			->where('ACTIVE', 'Y')
			->where('SERVER_TYPE', 'imap')
			->setLimit(1)
			->fetch() !== false
		;
		if (!$isAvailable)
		{
			return $result->addError(
				new Error(
					'The mailbox is not an available active IMAP mailbox.',
					self::ERROR_MAILBOX_UNAVAILABLE,
				),
			);
		}

		return $result;
	}
}
