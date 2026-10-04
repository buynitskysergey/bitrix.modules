<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internals\Service\Mailbox;

use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;

class AddressLock
{
	private const NAME_PREFIX = 'mail_mailbox_address_';
	private const DEFAULT_TIMEOUT = 5;

	private readonly Connection $connection;

	public function __construct(?Connection $connection = null)
	{
		$this->connection = $connection ?? Application::getConnection();
	}

	public function acquire(string $normalizedEmail, int $timeout = self::DEFAULT_TIMEOUT): bool
	{
		return $this->connection->lock(self::NAME_PREFIX . $normalizedEmail, $timeout);
	}

	public function release(string $normalizedEmail): void
	{
		$this->connection->unlock(self::NAME_PREFIX . $normalizedEmail);
	}
}
