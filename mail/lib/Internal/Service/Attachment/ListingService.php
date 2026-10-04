<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Attachment;

use Bitrix\Mail\Helper\MailboxAccess;
use Bitrix\Mail\Helper\Message\Loader\MessageLoader;
use Bitrix\Mail\Internals\MailMessageAttachmentTable;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

class ListingService
{
	public const ERROR_INVALID_REQUEST = 'INVALID_REQUEST';
	public const ERROR_MAILBOX_ACCESS_DENIED = 'MAILBOX_ACCESS_DENIED';

	public function __construct(
		private readonly ActiveMessageLocator $activeMessageLocator = new ActiveMessageLocator(),
	)
	{
	}

	public function getMessageAttachments(int $messageId, int $userId): Result
	{
		$result = new Result();
		$result->setData(['attachments' => []]);

		if ($messageId <= 0 || $userId <= 0)
		{
			return $result->addError(new Error('Invalid request.', self::ERROR_INVALID_REQUEST));
		}

		$message = $this->activeMessageLocator->find($messageId);

		if (
			$message === null
			|| !MailboxAccess::hasUserAccessToMailbox((int)$message['MAILBOX_ID'], $userId, withSharedMailboxes: true)
		)
		{
			return $result->addError(new Error('Access denied.', self::ERROR_MAILBOX_ACCESS_DENIED));
		}

		$rows = MailMessageAttachmentTable::getList([
			'select' => ['ID', 'MESSAGE_ID', 'FILE_ID', 'FILE_NAME', 'FILE_SIZE'],
			'filter' => ['=MESSAGE_ID' => $messageId, '>FILE_ID' => 0],
			'order' => ['ID' => 'ASC'],
		])->fetchAll();

		if (empty($rows))
		{
			return $result;
		}

		$attachmentsByMessage = MessageLoader::fillAttachmentUrls(
			MessageLoader::groupAttachmentsByMessageId($rows),
		);

		return $result->setData([
			'attachments' => $this->presentAttachments($attachmentsByMessage[$messageId] ?? []),
		]);
	}

	/** @internal */
	public function presentAttachments(array $attachments): array
	{
		$presented = [];
		foreach ($attachments as $attachment)
		{
			$presented[] = [
				'name' => (string)($attachment['name'] ?? ''),
				'size' => (string)\CFile::formatSize((int)($attachment['size'] ?? 0)),
				'url' => $attachment['url'] ?? null,
				'viewerAttrs' => $attachment['viewerAttrs'] ?? null,
				'icon' => FileIcon::resolve((string)($attachment['extension'] ?? '')),
			];
		}

		return $presented;
	}
}
