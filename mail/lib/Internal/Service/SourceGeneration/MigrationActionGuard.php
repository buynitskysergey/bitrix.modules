<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\SourceGeneration;

use Bitrix\Mail\Integration\MailService\MigrationStatus;
use Bitrix\Mail\Integration\MailService\MigrationStatusProvider;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class MigrationActionGuard
{
	public const ERROR_ACTION_BLOCKED = 'MAILBOX_MIGRATION_ACTION_BLOCKED';
	public const ERROR_STATUS_UNAVAILABLE = 'MAILBOX_MIGRATION_STATUS_UNAVAILABLE';

	public function __construct(
		private readonly MigrationStatusProvider $statusProvider = new MigrationStatusProvider(),
	)
	{
	}

	public function isBlocked(int $mailboxId): bool
	{
		try
		{
			$status = $this->statusProvider->getLatest($mailboxId);
		}
		catch (\Throwable)
		{
			return true;
		}

		return $status !== null && $status->visibility === 'active';
	}

	public function check(int $mailboxId): Result
	{
		try
		{
			$status = $this->statusProvider->getLatest($mailboxId);
		}
		catch (\Throwable)
		{
			return $this->statusUnavailableResult();
		}

		return $this->checkStatus($status);
	}

	/** @return array<int, Result> Results indexed by mailbox id. */
	public function checkMany(array $mailboxIds): array
	{
		$mailboxIds = array_values(array_unique(array_map('intval', $mailboxIds)));
		if ($mailboxIds === [])
		{
			return [];
		}

		try
		{
			$statuses = $this->statusProvider->getLatestForMailboxes($mailboxIds);
		}
		catch (\Throwable)
		{
			$results = [];
			foreach ($mailboxIds as $mailboxId)
			{
				$results[$mailboxId] = $this->statusUnavailableResult();
			}

			return $results;
		}

		$results = [];
		foreach ($mailboxIds as $mailboxId)
		{
			$results[$mailboxId] = $this->checkStatus($statuses[$mailboxId] ?? null);
		}

		return $results;
	}

	private function checkStatus(?MigrationStatus $status): Result
	{
		$result = new Result();

		return $status !== null && $status->visibility === 'active'
			? $result->addError(new Error(
				'The mailbox action is unavailable while its migration is active',
				self::ERROR_ACTION_BLOCKED,
			))
			: $result;
	}

	private function statusUnavailableResult(): Result
	{
		return (new Result())->addError(new Error(
			'The mailbox migration status is temporarily unavailable',
			self::ERROR_STATUS_UNAVAILABLE,
		));
	}
}
