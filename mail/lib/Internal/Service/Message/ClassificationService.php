<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Message;

use Bitrix\Mail\Helper\MailboxAccess;
use Bitrix\Mail\Internals\MailMessageMarkTable;
use Bitrix\Mail\MailMessageTable;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * A classification mark is shared across the mailbox (USER_ID = 0), so writing it takes access to the mailbox,
 * not the weaker per-message read access that also covers chat and calendar participants.
 *
 * Hence the two entrances: *ByUser() check that access and return the resolved mailbox id in the result data;
 * add() and removeLabel() are the system path (auto classification has no user at all) and check nothing, so
 * user-initiated code must not call them.
 *
 * The methods of the user entrance answer a message that does not exist exactly as they answer a message of
 * someone else's mailbox: a message id must not become an oracle of what is stored on the portal.
 */
class ClassificationService
{
	public const ERROR_MAILBOX_ACCESS_DENIED = 'MAILBOX_ACCESS_DENIED';

	private ?ClassificationSettings $settings = null;

	public function addByUser(int $userId, int $messageId, ClassificationLabel $label): Result
	{
		$result = $this->guardUserMutation($userId, $messageId);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$this->add($result->getData()['mailboxId'], $messageId, $label);

		return $result;
	}

	public function removeLabelByUser(int $userId, int $messageId, ClassificationLabel $label): Result
	{
		$result = $this->guardUserMutation($userId, $messageId);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$this->removeLabel($result->getData()['mailboxId'], $messageId, $label);

		return $result;
	}

	public function add(int $mailboxId, int $messageId, ClassificationLabel $label): void
	{
		$this->insertMarkRaw($mailboxId, $messageId, $label->markCode());
	}

	public function removeLabel(int $mailboxId, int $messageId, ClassificationLabel $label): void
	{
		$this->deleteMarkRaw($mailboxId, $messageId, $label->markCode());
	}

	private function guardUserMutation(int $userId, int $messageId): Result
	{
		$result = new Result();

		// CAccess::GetUserCodesArray(0) would answer with the group codes of an anonymous visitor
		if ($userId <= 0)
		{
			return $result->addError($this->accessDeniedError());
		}

		$message = $this->fetchMessage($messageId);
		if ($message === null)
		{
			return $result->addError($this->accessDeniedError());
		}

		$mailboxId = (int)$message['MAILBOX_ID'];
		if (!$this->hasMailboxAccess($mailboxId, $userId))
		{
			return $result->addError($this->accessDeniedError());
		}

		return $result->setData(['mailboxId' => $mailboxId]);
	}

	private function accessDeniedError(): Error
	{
		return new Error('Access to the mailbox is denied.', self::ERROR_MAILBOX_ACCESS_DENIED);
	}

	protected function fetchMessage(int $messageId): ?array
	{
		return MailMessageTable::getConsistentById($messageId, ['ID', 'MAILBOX_ID']);
	}

	/**
	 * A deleted mailbox keeps ACTIVE = 'N' and its access codes for a while, so ownership and sharing alone
	 * are not enough.
	 */
	protected function hasMailboxAccess(int $mailboxId, int $userId): bool
	{
		return $this->getSettings()->isClassifiableMailbox($mailboxId)
			&& MailboxAccess::hasUserAccessToMailbox($mailboxId, $userId, true)
		;
	}

	protected function getSettings(): ClassificationSettings
	{
		return $this->settings ??= new ClassificationSettings();
	}

	protected function insertMarkRaw(int $mailboxId, int $messageId, int $markCode): void
	{
		MailMessageMarkTable::insertIgnore($mailboxId, $messageId, $markCode, MailMessageMarkTable::SHARED_USER_ID);
	}

	protected function deleteMarkRaw(int $mailboxId, int $messageId, int $markCode): void
	{
		MailMessageMarkTable::deleteMark($mailboxId, $messageId, $markCode, MailMessageMarkTable::SHARED_USER_ID);
	}
}
