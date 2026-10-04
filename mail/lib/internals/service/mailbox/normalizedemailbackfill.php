<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internals\Service\Mailbox;

use Bitrix\Mail\MailboxTable;
use Bitrix\Main\DB\Connection;

final class NormalizedEmailBackfill
{
	private readonly \Closure $rowUpdater;

	public function __construct(
		private readonly Connection $connection,
		?\Closure $rowUpdater = null,
		private readonly ?EmailNormalizer $normalizer = null,
	)
	{
		$this->rowUpdater = $rowUpdater ?? $this->updateRow(...);
	}

	public function run(): void
	{
		$this->connection->clearCaches(MailboxTable::getTableName());
		$sqlHelper = $this->connection->getSqlHelper();
		$mailboxes = $this->connection->query(sprintf(
			'SELECT ID, EMAIL, NAME, LOGIN, EMAIL_NORMALIZED FROM %s',
			$sqlHelper->quote(MailboxTable::getTableName()),
		));
		$normalizer = $this->normalizer ?? new EmailNormalizer();
		while ($mailbox = $mailboxes->fetch())
		{
			$normalizedEmail = $normalizer->normalizeMailbox($mailbox);
			if ($mailbox['EMAIL_NORMALIZED'] !== $normalizedEmail)
			{
				($this->rowUpdater)((int)$mailbox['ID'], $normalizedEmail, $this->connection);
			}
		}
	}

	private function updateRow(int $mailboxId, ?string $normalizedEmail, Connection $connection): void
	{
		$sqlHelper = $connection->getSqlHelper();
		[$update, $binds] = $sqlHelper->prepareUpdate(MailboxTable::getTableName(), [
			'EMAIL_NORMALIZED' => $normalizedEmail,
		]);
		if ($update === '')
		{
			throw new \RuntimeException('Unable to prepare normalized mailbox email update.');
		}

		$connection->queryExecute(sprintf(
			'UPDATE %s SET %s WHERE ID = %u',
			$sqlHelper->quote(MailboxTable::getTableName()),
			$update,
			$mailboxId,
		), $binds);
	}
}
