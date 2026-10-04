<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\SourceGeneration;

use Bitrix\Mail\Helper\Mailbox\MailboxConnector;
use Bitrix\Mail\Internals\MailboxSourceGenerationTable;
use Bitrix\Mail\Internals\Service\Mailbox\EmailNormalizer;
use Bitrix\Mail\MailboxTable;
use Bitrix\Mail\Public\Service\Mailbox\AddressHistory;
use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

class MailboxMigrationSwitcher
{
	public const ERROR_MAILBOX_NOT_FOUND = 'MAIL_SOURCE_GENERATION_SWITCH_MAILBOX_NOT_FOUND';
	public const ERROR_EMAIL_INVALID = 'MAIL_SOURCE_GENERATION_SWITCH_EMAIL_INVALID';
	public const ERROR_EMAIL_UPDATE_FAILED = 'MAIL_SOURCE_GENERATION_SWITCH_EMAIL_UPDATE_FAILED';
	public const ERROR_SWITCH_FAILED = 'MAIL_SOURCE_GENERATION_BUSINESS_SWITCH_FAILED';
	public const RESULT_IMAP_DIRECTORIES = 'imapDirectories';

	public function __construct(
		private readonly Switcher $generationSwitcher = new Switcher(),
		private readonly SmtpSnapshotSwitcher $smtpSwitcher = new SmtpSnapshotSwitcher(),
		private readonly AddressHistory $addressHistory = new AddressHistory(),
		private readonly EmailNormalizer $emailNormalizer = new EmailNormalizer(),
	)
	{
	}

	/** @param array $heldLock The mailbox synchronization lease held over final sync and delta. */
	public function switch(
		int $mailboxId,
		string $operationId,
		ConnectionSnapshot $snapshot,
		int $expectedTargetRevision,
		int $expectedActiveGenerationId,
		array &$heldLock,
		array $preparedSmtp,
	): Result
	{
		$alreadySwitched = $this->alreadySwitched($mailboxId, $operationId);
		if ($alreadySwitched !== null)
		{
			return (new Result())->setData([
				'generationId' => $alreadySwitched,
				'alreadySwitched' => true,
			]);
		}

		$targetEmail = $this->emailNormalizer->normalize($snapshot->email());
		if ($targetEmail === null)
		{
			return (new Result())->addError(new Error(
				'A valid target mailbox address is expected',
				self::ERROR_EMAIL_INVALID,
			));
		}

		$apply = function() use (
			$mailboxId,
			$operationId,
			$expectedTargetRevision,
			$expectedActiveGenerationId,
			&$heldLock,
			$targetEmail,
			$preparedSmtp,
		): Result {
			return $this->applyWithinTransaction(
				$mailboxId,
				$operationId,
				$expectedTargetRevision,
				$expectedActiveGenerationId,
				$heldLock,
				$targetEmail,
				$preparedSmtp,
			);
		};

		return $apply();
	}

	/** Validates the target transports before the mailbox synchronization lease is acquired. */
	public function prepare(ConnectionSnapshot $snapshot): Result
	{
		$imap = $snapshot->imap();
		$validatedImap = $this->validateImapConnection($imap);
		if (!$validatedImap->isSuccess())
		{
			$result = new Result();
			foreach ($validatedImap->getErrors() as $error)
			{
				$result->addError(new Error(
					MigrationMetrics::withoutSecretsOf($imap, $error->getMessage()),
					MigrationService::ERROR_CONNECTION_UNAVAILABLE,
				));
			}

			return $result;
		}

		$directories = $validatedImap->getData()['directories'] ?? null;
		if (!is_array($directories) || !self::hasRequiredDirectoryRoles($directories))
		{
			return (new Result())->addError(new Error(
				'The new IMAP source has missing or ambiguous system folder roles',
				MigrationService::ERROR_DIRECTORY_ROLES_AMBIGUOUS,
			));
		}

		$preparedSmtp = $this->smtpSwitcher->prepare($snapshot);
		if ($preparedSmtp->isSuccess())
		{
			$preparedSmtp->setData($preparedSmtp->getData() + [self::RESULT_IMAP_DIRECTORIES => $directories]);
		}

		return $preparedSmtp;
	}

	protected function validateImapConnection(array $imap): Result
	{
		return (new MailboxConnector())->inspectMigrationConnectionSnapshot($imap);
	}

	public static function hasRequiredDirectoryRoles(array $directories): bool
	{
		$roles = [
			'sent' => 0,
			'trash' => 0,
			'spam' => 0,
		];

		foreach ($directories as $directory)
		{
			$flags = is_array($directory['flags'] ?? null) ? $directory['flags'] : [];
			$directoryRoles = [];
			foreach ($flags as $flag)
			{
				$normalized = mb_strtolower(ltrim((string)$flag, '\\'));
				if ($normalized === 'sent')
				{
					$directoryRoles['sent'] = true;
				}
				elseif ($normalized === 'trash')
				{
					$directoryRoles['trash'] = true;
				}
				elseif ($normalized === 'junk' || $normalized === 'spam')
				{
					$directoryRoles['spam'] = true;
				}
			}

			foreach (array_keys($directoryRoles) as $role)
			{
				$roles[$role]++;
			}
		}

		return count(array_filter($roles, static fn(int $count): bool => $count === 1)) === count($roles);
	}

	/**
	 * @param array<string, mixed> $preparedSmtp
	 */
	private function applyWithinTransaction(
		int $mailboxId,
		string $operationId,
		int $expectedTargetRevision,
		int $expectedActiveGenerationId,
		array &$heldLock,
		string $targetEmail,
		array $preparedSmtp,
	): Result
	{
		$result = new Result();
		$connection = Application::getConnection();
		$transactionStarted = false;
		$smtpChange = null;
		$originalLock = $heldLock;

		try
		{
			$connection->startTransaction();
			$transactionStarted = true;

			$mailbox = $this->lockMailbox($connection, $mailboxId);
			if ($mailbox === null)
			{
				$this->rollback($connection);
				$transactionStarted = false;

				return $result->addError(new Error(
					'The mailbox is not found',
					self::ERROR_MAILBOX_NOT_FOUND,
				));
			}

			$currentEmail = $this->emailNormalizer->normalizeMailbox($mailbox);
			if ($currentEmail === null)
			{
				$this->rollback($connection);
				$transactionStarted = false;

				return $result->addError(new Error(
					'A valid current mailbox address is expected',
					self::ERROR_EMAIL_INVALID,
				));
			}

			$activated = $this->generationSwitcher->activateWithinTransaction(
				$mailboxId,
				$operationId,
				$expectedActiveGenerationId,
				$heldLock,
				$expectedTargetRevision,
			);
			if (!$activated->isSuccess())
			{
				$this->rollback($connection);
				$transactionStarted = false;
				$heldLock = $originalLock;

				return $activated;
			}

			if ($currentEmail !== $targetEmail)
			{
				$updated = MailboxTable::update($mailboxId, ['EMAIL' => $targetEmail]);
				if (!$updated->isSuccess())
				{
					$this->rollback($connection);
					$transactionStarted = false;
					$heldLock = $originalLock;

					return $result->addError(new Error(
						implode('; ', $updated->getErrorMessages()),
						self::ERROR_EMAIL_UPDATE_FAILED,
					));
				}

				$registered = $this->addressHistory->registerWithinTransaction($mailboxId, $currentEmail);
				if (!$registered->isSuccess())
				{
					$this->rollback($connection);
					$transactionStarted = false;
					$heldLock = $originalLock;

					return $registered;
				}
			}

			$appliedSmtp = $this->smtpSwitcher->applyWithinTransaction(
				$mailboxId,
				$targetEmail,
				$mailbox,
				$preparedSmtp,
			);
			if (!$appliedSmtp->isSuccess())
			{
				$this->rollback($connection);
				$transactionStarted = false;
				$heldLock = $originalLock;

				return $appliedSmtp;
			}
			$smtpChange = $appliedSmtp->getData()['cacheChange'];

			$connection->commitTransaction();
			$transactionStarted = false;
		}
		catch (\Throwable)
		{
			if ($transactionStarted)
			{
				$this->rollback($connection);
			}
			$heldLock = $originalLock;

			return $result->addError(new Error(
				'The mailbox migration switch failed',
				self::ERROR_SWITCH_FAILED,
			));
		}

		try
		{
			$this->generationSwitcher->afterCommit($mailboxId);
		}
		finally
		{
			if ($smtpChange !== null)
			{
				$this->smtpSwitcher->afterCommit($smtpChange, $mailboxId);
			}
		}

		return $result->setData($activated->getData());
	}

	/** @return array<string, mixed>|null */
	private function lockMailbox(Connection $connection, int $mailboxId): ?array
	{
		$mailbox = $connection->query(sprintf(
			'SELECT ID, EMAIL, NAME, LOGIN, USERNAME, USER_ID FROM %s WHERE ID = %u FOR UPDATE',
			MailboxTable::getTableName(),
			$mailboxId,
		))->fetch();

		return $mailbox ?: null;
	}

	private function alreadySwitched(int $mailboxId, string $operationId): ?int
	{
		$generation = MailboxSourceGenerationTable::getList([
			'select' => ['ID', 'STATUS'],
			'filter' => [
				'=MAILBOX_ID' => $mailboxId,
				'=OPERATION_ID' => $operationId,
			],
		])->fetch();
		if (
			$generation === false
			|| (string)$generation['STATUS'] !== MailboxSourceGenerationTable::STATUS_ACTIVE
		)
		{
			return null;
		}

		$generationId = (int)$generation['ID'];

		return $this->generationSwitcher->getActiveGenerationId($mailboxId) === $generationId
			&& $this->generationSwitcher->isProjectionConfirmed($mailboxId, $generationId)
			? $generationId
			: null
		;
	}

	private function rollback(Connection $connection): void
	{
		try
		{
			$connection->rollbackTransaction();
		}
		catch (\Throwable)
		{
		}
	}
}
