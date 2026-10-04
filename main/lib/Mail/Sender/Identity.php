<?php

namespace Bitrix\Main\Mail\Sender;

use Bitrix\Main\ArgumentException;

/**
 * Portable reference to the sender used for an outgoing message.
 * Carries coordinates only: no address, no database access.
 */
final class Identity
{
	public readonly ?string $mailboxModuleId;
	public readonly ?int $mailboxParentId;
	public readonly ?int $senderId;

	private function __construct(?string $mailboxModuleId, ?int $mailboxParentId, ?int $senderId)
	{
		$hasMailboxRef = $mailboxModuleId !== null && $mailboxModuleId !== '' && $mailboxParentId !== null && $mailboxParentId > 0;
		$hasSenderRef = $senderId !== null && $senderId > 0;

		if (!$hasMailboxRef && !$hasSenderRef)
		{
			throw new ArgumentException('Sender identity requires a mailbox reference or a sender reference');
		}

		$this->mailboxModuleId = $hasMailboxRef ? $mailboxModuleId : null;
		$this->mailboxParentId = $hasMailboxRef ? $mailboxParentId : null;
		$this->senderId = $hasSenderRef ? $senderId : null;
	}

	public static function fromMailbox(int $mailboxParentId, string $mailboxModuleId = 'mail'): self
	{
		return new self($mailboxModuleId, $mailboxParentId, null);
	}

	public static function fromSender(int $senderId): self
	{
		return new self(null, null, $senderId);
	}

	public static function fromMailboxAndSender(int $mailboxParentId, int $senderId, string $mailboxModuleId = 'mail'): self
	{
		return new self($mailboxModuleId, $mailboxParentId, $senderId);
	}

	public function hasMailboxRef(): bool
	{
		return $this->mailboxParentId !== null;
	}

	public function hasSenderRef(): bool
	{
		return $this->senderId !== null;
	}
}
