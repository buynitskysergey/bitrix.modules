<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Message;

use Bitrix\Mail\Helper\Enum\Mailbox\EntityOptionsType;
use Bitrix\Mail\Helper\Mailbox\Options\EntityOptionsHelper;
use Bitrix\Mail\Internals\MailEntityOptionsTable;

/**
 * A message that arrived without a body waits for it: the flag marks such a message, and
 * classification runs once the body is synced. This is process state, not a mark of the message.
 */
class ClassifyPendingService
{
	public const PROPERTY = 'CLASSIFY_PENDING';

	/** Deleting a folder or a mailbox brings tens of thousands of ids in one event. */
	public const DELETE_CHUNK_SIZE = 1000;

	public function mark(int $mailboxId, int $messageId): void
	{
		EntityOptionsHelper::setValue(
			$mailboxId,
			EntityOptionsType::Message,
			(string)$messageId,
			self::PROPERTY,
			'Y',
		);
	}

	public function isPending(int $mailboxId, int $messageId): bool
	{
		return EntityOptionsHelper::getValue(
			$mailboxId,
			EntityOptionsType::Message,
			(string)$messageId,
			self::PROPERTY,
		) === 'Y';
	}

	/**
	 * Raw delete on purpose: an ORM delete ends with Entity::cleanCache(), which drops a cache directory
	 * holding nothing of ours.
	 */
	public function clear(int $mailboxId, int $messageId): void
	{
		$this->deleteChunkRaw($mailboxId, [$messageId]);
	}

	/**
	 * @param int[] $messageIds
	 */
	public function clearMany(int $mailboxId, array $messageIds): void
	{
		if ($messageIds === [])
		{
			return;
		}

		foreach (array_chunk($messageIds, self::DELETE_CHUNK_SIZE) as $messageIdsChunk)
		{
			$this->deleteChunkRaw($mailboxId, $messageIdsChunk);
		}
	}

	/**
	 * @param int[] $messageIds
	 */
	protected function deleteChunkRaw(int $mailboxId, array $messageIds): void
	{
		MailEntityOptionsTable::deleteList([
			'=MAILBOX_ID' => $mailboxId,
			'=ENTITY_TYPE' => EntityOptionsType::Message->value,
			'@ENTITY_ID' => array_map('strval', $messageIds),
			'=PROPERTY_NAME' => self::PROPERTY,
		]);
	}
}
