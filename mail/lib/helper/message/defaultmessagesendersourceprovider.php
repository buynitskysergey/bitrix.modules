<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper\Message;

use Bitrix\Mail\Helper;
use Bitrix\Mail\Internals\MailMessageAttachmentTable;
use Bitrix\Mail\Integration\Attachment;
use Bitrix\Mail\MailMessageTable;

final class DefaultMessageSenderSourceProvider implements MessageSenderSourceProvider
{
	public function loadMessage(int $messageId): ?array
	{
		$message = MailMessageTable::query()
			->setSelect(['*', 'MAILBOX_EMAIL' => 'MAILBOX.EMAIL'])
			->where('ID', $messageId)
			->setLimit(1)
			->fetch()
		;

		return is_array($message) ? $message : null;
	}

	public function hasAccess(array &$message, int $userId): bool
	{
		return Helper\Message::hasAccess($message, $userId);
	}

	public function materialize(array $message): ?array
	{
		return Attachment::materializeAttachmentsForMessage($message);
	}

	public function loadAttachmentRows(int $messageId): array
	{
		return MailMessageAttachmentTable::query()
			->setSelect(['ID', 'FILE_ID', 'FILE_NAME', 'FILE_SIZE', 'CONTENT_TYPE', 'EXTERNAL_LINK_ID'])
			->where('MESSAGE_ID', $messageId)
			->fetchAll()
		;
	}

	public function resolveFile(int $fileId): array|false|null
	{
		return \CFile::makeFileArray($fileId);
	}
}
