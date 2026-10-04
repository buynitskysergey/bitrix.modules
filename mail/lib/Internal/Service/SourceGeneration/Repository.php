<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\SourceGeneration;

use Bitrix\Mail\Internal\Entity\SourceGeneration\SourceGeneration;
use Bitrix\Mail\Internals\MailboxSourceGenerationTable;
use Bitrix\Mail\MailboxTable;

/**
 * Read access to source generations for context resolution.
 */
class Repository
{
	public function getById(int $generationId): ?SourceGeneration
	{
		if ($generationId <= 0)
		{
			return null;
		}

		$row = $this->fetchGenerationRow($generationId);

		return $row === null ? null : $this->hydrate($row);
	}

	/**
	 * Returns the active generation pointer of the mailbox, 0 for a mailbox
	 * that has not been backfilled yet or does not exist.
	 */
	public function getActiveGenerationId(int $mailboxId): int
	{
		if ($mailboxId <= 0)
		{
			return 0;
		}

		return $this->fetchActiveGenerationId($mailboxId);
	}

	protected function fetchGenerationRow(int $generationId): ?array
	{
		$row = MailboxSourceGenerationTable::getByPrimary(
			$generationId,
			['select' => ['ID', 'MAILBOX_ID', 'OPERATION_ID', 'STATUS', 'REVISION', 'OPTIONS']],
		)->fetch();

		return $row ?: null;
	}

	protected function fetchActiveGenerationId(int $mailboxId): int
	{
		$row = MailboxTable::getByPrimary(
			$mailboxId,
			['select' => ['ID', 'ACTIVE_GENERATION_ID']],
		)->fetch();

		return $row ? (int)$row['ACTIVE_GENERATION_ID'] : 0;
	}

	private function hydrate(array $row): SourceGeneration
	{
		$options = is_array($row['OPTIONS'] ?? null) ? $row['OPTIONS'] : [];

		return new SourceGeneration(
			id: (int)$row['ID'],
			mailboxId: (int)$row['MAILBOX_ID'],
			operationId: (string)$row['OPERATION_ID'],
			status: (string)$row['STATUS'],
			revision: (int)$row['REVISION'],
			migratorService: trim((string)($options[MailboxSourceGenerationTable::OPTION_MIGRATOR_SERVICE] ?? '')),
		);
	}
}
