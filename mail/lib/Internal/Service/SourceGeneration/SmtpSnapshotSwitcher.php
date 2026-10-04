<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\SourceGeneration;

use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\Mail\Internal\SenderTable;
use Bitrix\Main\Mail\Sender;
use Bitrix\Main\Result;

class SmtpSnapshotSwitcher
{
	public const ERROR_CONNECTION_UNAVAILABLE = 'MAIL_SOURCE_GENERATION_SMTP_CONNECTION_UNAVAILABLE';
	public const ERROR_SENDER_AMBIGUOUS = 'MAIL_SOURCE_GENERATION_SMTP_SENDER_AMBIGUOUS';
	public const ERROR_SENDER_WRITE_FAILED = 'MAIL_SOURCE_GENERATION_SMTP_SENDER_WRITE_FAILED';

	public function prepare(ConnectionSnapshot $snapshot): Result
	{
		$result = new Result();
		$smtp = $snapshot->smtp();

		$config = [
			'server' => (string)($smtp['SERVER'] ?? ''),
			'port' => (int)($smtp['PORT'] ?? 0),
			'protocol' => (string)($smtp['PROTOCOL'] ?? ''),
			'login' => (string)($smtp['LOGIN'] ?? ''),
			'password' => (string)($smtp['PASSWORD'] ?? ''),
			'isOauth' => false,
		];
		if (isset($smtp['LIMIT']))
		{
			$config['limit'] = (int)$smtp['LIMIT'];
		}
		$preparedLimit = $config['limit'] ?? null;

		$validated = $this->validateConnection($config);
		if (!$validated->isSuccess())
		{
			foreach ($validated->getErrors() as $error)
			{
				$result->addError(new Error(
					MigrationMetrics::withoutSecretsOf($config, $error->getMessage()),
					self::ERROR_CONNECTION_UNAVAILABLE,
				));
			}

			return $result;
		}
		if ($preparedLimit !== null)
		{
			$config['limit'] = $preparedLimit;
		}

		return $result->setData(['smtp' => $config]);
	}

	protected function validateConnection(array &$smtp): Result
	{
		return Sender::prepareSmtpConfigForSender($smtp);
	}

	/**
	 * @param array<string, mixed> $mailbox
	 * @param array<string, mixed> $smtp
	 */
	public function applyWithinTransaction(
		int $mailboxId,
		string $email,
		array $mailbox,
		array $smtp,
	): Result
	{
		$result = new Result();
		$connection = Application::getConnection();
		$lockedSenderIds = [];
		$rows = $connection->query(sprintf(
			"SELECT ID FROM %s WHERE PARENT_MODULE_ID = 'mail' AND PARENT_ID = %u ORDER BY ID FOR UPDATE",
			SenderTable::getTableName(),
			$mailboxId,
		));
		while ($row = $rows->fetch())
		{
			$lockedSenderIds[] = (int)$row['ID'];
		}

		if (count($lockedSenderIds) > 1)
		{
			return $result->addError(new Error(
				sprintf('The mailbox %u has more than one SMTP sender', $mailboxId),
				self::ERROR_SENDER_AMBIGUOUS,
			));
		}

		$sender = $lockedSenderIds === []
			? null
			: SenderTable::getByPrimary($lockedSenderIds[0], [
				'select' => ['ID', 'EMAIL', 'OPTIONS'],
			])->fetch()
		;
		if ($lockedSenderIds !== [] && $sender === false)
		{
			return $result->addError(new Error(
				'The mailbox SMTP sender is unreadable',
				self::ERROR_SENDER_WRITE_FAILED,
			));
		}

		$options = is_array($sender['OPTIONS'] ?? null)
			? $sender['OPTIONS']
			: ['source' => 'mail.client.config']
		;
		$options['smtp'] = $smtp;
		$storedOptions = SenderTable::getEntity()
			->getField('OPTIONS')
			->modifyValueBeforeSave($options, ['OPTIONS' => $options])
		;

		if ($sender === null)
		{
			$senderId = (int)$connection->add(SenderTable::getTableName(), [
				'EMAIL' => $email,
				'NAME' => (string)($mailbox['USERNAME'] ?? ''),
				'USER_ID' => (int)($mailbox['USER_ID'] ?? 0),
				'IS_CONFIRMED' => 1,
				'IS_PUBLIC' => 0,
				'OPTIONS' => $storedOptions,
				'PARENT_MODULE_ID' => 'mail',
				'PARENT_ID' => $mailboxId,
			]);
			if ($senderId <= 0)
			{
				return $result->addError(new Error(
					'The mailbox SMTP sender was not created',
					self::ERROR_SENDER_WRITE_FAILED,
				));
			}

			return $result->setData(['cacheChange' => [
				'senderId' => $senderId,
				'oldEmail' => null,
				'newEmail' => $email,
			]]);
		}

		$senderId = (int)$sender['ID'];
		$sqlHelper = $connection->getSqlHelper();
		[$update, $binds] = $sqlHelper->prepareUpdate(SenderTable::getTableName(), [
			'EMAIL' => $email,
			'IS_CONFIRMED' => 1,
			'OPTIONS' => $storedOptions,
		]);
		$connection->queryExecute(sprintf(
			'UPDATE %s SET %s WHERE ID = %u',
			SenderTable::getTableName(),
			$update,
			$senderId,
		), $binds);
		return $result->setData(['cacheChange' => [
			'senderId' => $senderId,
			'oldEmail' => (string)$sender['EMAIL'],
			'newEmail' => $email,
		]]);
	}

	/** @param array{senderId: int, oldEmail: ?string, newEmail: string} $change */
	public function afterCommit(array $change, int $mailboxId): void
	{
		$senderId = (int)$change['senderId'];
		$oldEmail = $change['oldEmail'] ?? null;
		$newEmail = (string)$change['newEmail'];

		Sender::clearSenderCache($senderId, is_string($oldEmail) ? $oldEmail : null);
		Sender::clearSenderCache($senderId, $newEmail);
		Sender::clearIdentitySenderCache($senderId, 'mail', $mailboxId);
	}
}
