<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internals\Repository;

use Bitrix\Mail\Internals\Model\MailboxAddressAliasTable;
use Bitrix\Mail\MailboxTable;
use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\Error;
use Bitrix\Main\ORM\Data\AddResult;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\Result;
use Bitrix\Main\SystemException;

final class MailboxAddressAliasRepository
{
	private const ERROR_ALIAS_NOT_FOUND = 'MAIL_MAILBOX_ALIAS_NOT_FOUND';
	private const ERROR_ALIAS_NOT_ORPHAN = 'MAIL_MAILBOX_ALIAS_NOT_ORPHAN';

	public function __construct(
		private readonly ?\Closure $beforeConditionalOrphanDelete = null,
		private readonly ?Connection $connection = null,
	)
	{
	}

	public function findByEmail(string $email): array
	{
		return MailboxAddressAliasTable::getList([
			'select' => ['ID', 'MAILBOX_ID', 'EMAIL'],
			'filter' => ['=EMAIL' => $email],
			'order' => ['ID' => 'ASC'],
		])->fetchAll();
	}

	/**
	 * @return int[]
	 */
	public function findMailboxIdsByEmail(string $email): array
	{
		$rows = MailboxAddressAliasTable::getList([
			'select' => ['MAILBOX_ID'],
			'filter' => ['=EMAIL' => $email],
		])->fetchAll();

		return array_map(
			static fn(array $row): int => (int)$row['MAILBOX_ID'],
			$rows,
		);
	}

	public function exists(int $mailboxId, string $email): bool
	{
		return MailboxAddressAliasTable::getCount([
			'=MAILBOX_ID' => $mailboxId,
			'=EMAIL' => $email,
		]) > 0;
	}

	public function add(int $mailboxId, string $email): AddResult
	{
		return MailboxAddressAliasTable::addInsertIgnore([
			'MAILBOX_ID' => $mailboxId,
			'EMAIL' => $email,
		]);
	}

	public function deleteByMailboxId(int $mailboxId): void
	{
		$aliasIds = MailboxAddressAliasTable::getList([
			'select' => ['ID'],
			'filter' => ['=MAILBOX_ID' => $mailboxId],
		])->fetchCollection()->getIdList();

		foreach ($aliasIds as $aliasId)
		{
			$deleteResult = MailboxAddressAliasTable::delete($aliasId);
			if (!$deleteResult->isSuccess())
			{
				throw new SystemException(implode('; ', $deleteResult->getErrorMessages()));
			}
		}
	}

	public function findOrphans(int $limit, ?int $afterId = null): array
	{
		$filter = ['=MAILBOX.ID' => null];
		if ($afterId !== null)
		{
			$filter['>ID'] = $afterId;
		}

		return MailboxAddressAliasTable::getList([
			'select' => ['ID', 'MAILBOX_ID', 'EMAIL'],
			'filter' => $filter,
			'order' => ['ID' => 'ASC'],
			'limit' => $limit,
			'runtime' => [
				(new Reference(
					'MAILBOX',
					MailboxTable::class,
					Join::on('this.MAILBOX_ID', 'ref.ID'),
				))->configureJoinType(Join::TYPE_LEFT),
			],
		])->fetchAll();
	}

	public function deleteOrphan(int $aliasId): Result
	{
		$result = new Result();
		$alias = MailboxAddressAliasTable::getById($aliasId)->fetch();
		if ($alias === false)
		{
			$result->addError(new Error('Mailbox alias not found.', self::ERROR_ALIAS_NOT_FOUND));

			return $result;
		}

		if (MailboxTable::getCount(['=ID' => (int)$alias['MAILBOX_ID']]) > 0)
		{
			$result->addError(new Error('Mailbox alias is not orphaned.', self::ERROR_ALIAS_NOT_ORPHAN));

			return $result;
		}

		if ($this->beforeConditionalOrphanDelete !== null)
		{
			($this->beforeConditionalOrphanDelete)($alias);
		}

		$connection = $this->connection ?? Application::getConnection();
		$sqlHelper = $connection->getSqlHelper();
		$aliasTable = $sqlHelper->quote(MailboxAddressAliasTable::getTableName());
		$mailboxTable = $sqlHelper->quote(MailboxTable::getTableName());
		$idColumn = $sqlHelper->quote('ID');
		$mailboxIdColumn = $sqlHelper->quote('MAILBOX_ID');
		$connection->queryExecute(sprintf(
			'DELETE FROM %1$s WHERE %2$s = %3$u AND NOT EXISTS ('
				. 'SELECT 1 FROM %4$s WHERE %4$s.%2$s = %1$s.%5$s'
				. ')',
			$aliasTable,
			$idColumn,
			$aliasId,
			$mailboxTable,
			$mailboxIdColumn,
		));
		if ($connection->getAffectedRowsCount() === 0)
		{
			if (MailboxAddressAliasTable::getById($aliasId)->fetch() === false)
			{
				$result->addError(new Error('Mailbox alias not found.', self::ERROR_ALIAS_NOT_FOUND));
			}
			elseif (MailboxTable::getCount(['=ID' => (int)$alias['MAILBOX_ID']]) > 0)
			{
				$result->addError(new Error('Mailbox alias is not orphaned.', self::ERROR_ALIAS_NOT_ORPHAN));
			}
			else
			{
				$result->addError(new Error('Mailbox orphan alias was not deleted.'));
			}
		}

		return $result;
	}
}
