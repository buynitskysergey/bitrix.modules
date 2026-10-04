<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Entity\SourceGeneration;

use Bitrix\Mail\Internals\MailboxSourceGenerationTable;
use Bitrix\Main\ArgumentOutOfRangeException;

/**
 * Immutable source generation context of a physical operation (TPL-01).
 *
 * Every physical operation receives exactly one context resolved once at the entry
 * point of a request or a job. A bare GENERATION_ID must never travel without its
 * status and purpose, so the context carries all of them and computes the access
 * level of the operation from the TPL-01 permission matrix:
 *
 *   purpose \ status   | ACTIVE     | PREPARING        | ARCHIVED
 *   NORMAL_SYNC        | read/write | deny             | deny
 *   MIGRATION_IMPORT   | deny       | append/link only | deny
 *
 * Generation id 0 is the implicit G1 of a mailbox that has not been backfilled yet.
 * It behaves as an ACTIVE generation for NORMAL_SYNC.
 */
final readonly class Context
{
	public const PURPOSE_NORMAL_SYNC = 'NORMAL_SYNC';
	public const PURPOSE_MIGRATION_IMPORT = 'MIGRATION_IMPORT';

	public const ACCESS_DENY = 'DENY';
	public const ACCESS_READ_WRITE = 'READ_WRITE';
	public const ACCESS_APPEND_ONLY = 'APPEND_ONLY';

	/**
	 * @param string $migratorService The managed transfer service the operation declared,
	 *        empty when it declared none.
	 * @throws ArgumentOutOfRangeException
	 */
	public function __construct(
		public int $mailboxId,
		public int $generationId,
		public string $status,
		public string $operationId,
		public string $purpose,
		public string $migratorService = '',
	)
	{
		if ($this->mailboxId <= 0)
		{
			throw new ArgumentOutOfRangeException('mailboxId', 1);
		}

		if ($this->generationId < 0)
		{
			throw new ArgumentOutOfRangeException('generationId', 0);
		}

		$knownStatuses = [
			MailboxSourceGenerationTable::STATUS_PREPARING,
			MailboxSourceGenerationTable::STATUS_ACTIVE,
			MailboxSourceGenerationTable::STATUS_ARCHIVED,
		];
		if (!in_array($this->status, $knownStatuses, true))
		{
			throw new ArgumentOutOfRangeException('status', $knownStatuses);
		}

		$knownPurposes = [
			self::PURPOSE_NORMAL_SYNC,
			self::PURPOSE_MIGRATION_IMPORT,
		];
		if (!in_array($this->purpose, $knownPurposes, true))
		{
			throw new ArgumentOutOfRangeException('purpose', $knownPurposes);
		}

		// A migration import targets one concrete prepared generation, never the implicit G1
		if ($this->purpose === self::PURPOSE_MIGRATION_IMPORT && $this->generationId <= 0)
		{
			throw new ArgumentOutOfRangeException('generationId', 1);
		}
	}

	public function getAccessLevel(): string
	{
		if (
			$this->purpose === self::PURPOSE_NORMAL_SYNC
			&& $this->status === MailboxSourceGenerationTable::STATUS_ACTIVE
		)
		{
			return self::ACCESS_READ_WRITE;
		}

		if (
			$this->purpose === self::PURPOSE_MIGRATION_IMPORT
			&& $this->status === MailboxSourceGenerationTable::STATUS_PREPARING
		)
		{
			return self::ACCESS_APPEND_ONLY;
		}

		return self::ACCESS_DENY;
	}

	public function canRead(): bool
	{
		return $this->getAccessLevel() !== self::ACCESS_DENY;
	}

	/**
	 * Full mutation of physical data: update, delete, cleanup.
	 */
	public function canWrite(): bool
	{
		return $this->getAccessLevel() === self::ACCESS_READ_WRITE;
	}

	/**
	 * Appending new physical rows and linking them to logical messages.
	 */
	public function canAppend(): bool
	{
		return $this->getAccessLevel() !== self::ACCESS_DENY;
	}

	/**
	 * The initial generation of the mailbox: the implicit 0 or the G1 created by
	 * the backfill over the legacy physical rows. Its uid row ids keep the
	 * historical formula, so old identifiers are never rewritten.
	 */
	public function isInitialGeneration(): bool
	{
		return $this->generationId === 0
			|| $this->operationId === MailboxSourceGenerationTable::OPERATION_G1_BACKFILL;
	}

	/**
	 * The generation number the uid row id formula receives: 0 keeps the
	 * historical byte-identical formula of G1, a real id turns on the versioned
	 * generation prefix (see UidIdentity).
	 */
	public function getUidFormulaGenerationId(): int
	{
		return $this->isInitialGeneration() ? 0 : $this->generationId;
	}

	public function isNormalSync(): bool
	{
		return $this->purpose === self::PURPOSE_NORMAL_SYNC;
	}

	public function isMigrationImport(): bool
	{
		return $this->purpose === self::PURPOSE_MIGRATION_IMPORT;
	}

	/**
	 * Whether the transferable identifier a MIME header carries may be read at all.
	 *
	 * Nothing authenticates such a header: a letter of an ordinary delivery carries
	 * whatever its sender wrote there. It is an optional channel of the correspondence a
	 * managed transfer service passes through its manifest or its API, so the channel
	 * exists only for an operation that declared such a service.
	 */
	public function acceptsMigratorReference(): bool
	{
		return $this->isMigrationImport() && $this->migratorService !== '';
	}

	/**
	 * Whether the one time mark the tail append stage puts into a letter of its own may be
	 * read at all.
	 *
	 * The same shape of gate as the one above and for the same reason - nothing
	 * authenticates a MIME header - but a narrower channel: no managed service has to be
	 * declared, because the mark is ours and was written down by us before the letter
	 * existed on the server. What it is bounded by instead is the operation: only an import
	 * into a generation being prepared ever reads such a header, so an ordinary
	 * synchronization of the mailbox never looks at it, whatever a letter of it carries.
	 *
	 * The mark alone still decides nothing: the identity comes from the record the stage
	 * wrote ahead of the append, and a mark with no record of its own leads nowhere.
	 */
	public function acceptsTailMark(): bool
	{
		return $this->isMigrationImport() && $this->getAccessLevel() === self::ACCESS_APPEND_ONLY;
	}
}
