<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Message;

use Bitrix\Mail\Helper\Config\Feature;
use Bitrix\Mail\Helper\Enum\Mailbox\EntityOptionsType;
use Bitrix\Mail\Helper\Mailbox\Options\EntityOptionsHelper;
use Bitrix\Mail\MailboxTable;

/**
 * Two gates of auto classification: a global switch shared with the assistant surface, and a per-mailbox
 * allow list a mailbox stays out of until it is added explicitly.
 */
class ClassificationSettings
{
	public const MAILBOX_ALLOW_PROPERTY = 'AUTO_CLASSIFY_ENABLED';

	/** One mailbox is asked about once per message of a sync run, so the answer is kept. */
	private array $mailboxAllowCache = [];

	public function isAutoClassifyEnabled(): bool
	{
		return Feature::isAutoClassifyIncomingAvailable();
	}

	/**
	 * The allow list is asked first: the mailbox row is then read only for the few mailboxes in it.
	 */
	public function isAutoClassifyAllowedForMailbox(int $mailboxId): bool
	{
		return $this->mailboxAllowCache[$mailboxId] ??= (
			$this->readMailboxAllowFlag($mailboxId) === 'Y'
			&& $this->isClassifiableMailbox($mailboxId)
		);
	}

	/**
	 * One answer for both sides of the feature: the label path means a live imap mailbox alone, while
	 * Mailbox::instance() serves imap, controller, domain and crdomain with the same Imap helper.
	 */
	public function isClassifiableMailbox(int $mailboxId): bool
	{
		return (bool)MailboxTable::query()
			->setSelect(['ID'])
			->where('ID', $mailboxId)
			->where('ACTIVE', 'Y')
			->where('SERVER_TYPE', 'imap')
			->setLimit(1)
			->fetch()
		;
	}

	protected function readMailboxAllowFlag(int $mailboxId): ?string
	{
		return EntityOptionsHelper::getValue(
			$mailboxId,
			EntityOptionsType::Mailbox,
			(string)$mailboxId,
			self::MAILBOX_ALLOW_PROPERTY,
		);
	}
}
