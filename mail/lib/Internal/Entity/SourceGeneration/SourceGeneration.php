<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Entity\SourceGeneration;

use Bitrix\Mail\Internals\MailboxSourceGenerationTable;

/**
 * Physical IMAP source generation of a logical mailbox.
 * The generation table is the single source of truth for IMAP credentials.
 * Connection fields of b_mail_mailbox are a legacy projection of the active generation.
 */
final readonly class SourceGeneration
{
	/**
	 * @param string $migratorService The managed transfer service the operation of this
	 *        generation declared, empty when it declared none.
	 */
	public function __construct(
		public int $id,
		public int $mailboxId,
		public string $operationId,
		public string $status,
		public int $revision,
		public string $migratorService = '',
	)
	{
	}

	public function isPreparing(): bool
	{
		return $this->status === MailboxSourceGenerationTable::STATUS_PREPARING;
	}

	public function isActive(): bool
	{
		return $this->status === MailboxSourceGenerationTable::STATUS_ACTIVE;
	}

	public function isArchived(): bool
	{
		return $this->status === MailboxSourceGenerationTable::STATUS_ARCHIVED;
	}
}
