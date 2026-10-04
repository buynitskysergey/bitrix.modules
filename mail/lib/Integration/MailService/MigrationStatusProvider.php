<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\MailService;

use Bitrix\Mail\Internal\Service\SourceGeneration\MigrationOperationSchema;
use Bitrix\Mail\Internal\Service\SourceGeneration\MigrationService;
use Bitrix\Mail\Internal\Service\SourceGeneration\MigrationStage;
use Bitrix\Mail\Internal\Service\SourceGeneration\OperationTracker;
use Bitrix\Mail\Internals\MigrationOperationTable;
use Bitrix\Main\Type\DateTime;

final class MigrationStatusProvider
{
	public function __construct(
		private readonly OperationTracker $tracker = new OperationTracker(),
		private readonly MigrationOperationSchema $schema = new MigrationOperationSchema(),
	)
	{
	}

	public function get(int $mailboxId, string $operationId): ?MigrationStatus
	{
		if (!$this->schema->isReady())
		{
			return null;
		}

		return $this->fromRow($this->tracker->find($mailboxId, $operationId));
	}

	public function getLatest(int $mailboxId): ?MigrationStatus
	{
		if (!$this->schema->isReady())
		{
			return null;
		}

		return $this->fromRow($this->tracker->findLatestForMailbox($mailboxId));
	}

	/** @return array<int, MigrationStatus> Statuses indexed by mailbox id. */
	public function getLatestForMailboxes(array $mailboxIds): array
	{
		if (!$this->schema->isReady())
		{
			return [];
		}

		$rows = $this->tracker->findLatestForMailboxes($mailboxIds);

		$statuses = [];
		foreach ($rows as $mailboxId => $row)
		{
			$status = $this->fromRow($row);
			if ($status !== null)
			{
				$statuses[(int)$mailboxId] = $status;
			}
		}

		return $statuses;
	}

	public function fromRow(?array $operation): ?MigrationStatus
	{
		if ($operation === null)
		{
			return null;
		}

		$stage = MigrationStage::tryFrom((string)$operation['STAGE']) ?? MigrationStage::Legacy;
		$state = (string)$operation['EXEC_STATE'];
		$status = $this->statusOf($state, $stage);
		$authorized = (string)$operation['SWITCH_AUTHORIZED'] === 'Y';
		$visibility = !$authorized
			? 'hidden'
			: (in_array($state, [MigrationOperationTable::STATE_DONE, MigrationOperationTable::STATE_CANCELLED], true)
				? 'terminal'
				: 'active');

		$nextRun = $operation['NEXT_RUN'] ?? null;

		return new MigrationStatus(
			status: $status,
			reason: $this->reasonOf($operation, $stage),
			errorCode: $this->nullableString($operation['LAST_ERROR_CODE'] ?? null),
			errorOwner: $this->nullableString($operation['ERROR_OWNER'] ?? null),
			retryable: (string)$operation['AUTO_RETRY'] === 'Y',
			nextAttemptAt: $nextRun instanceof DateTime ? $nextRun->getTimestamp() : null,
			operationId: (string)$operation['OPERATION_ID'],
			mailboxId: (int)$operation['MAILBOX_ID'],
			visibility: $visibility,
			publicStatus: $visibility === 'hidden' ? null : $status,
		);
	}

	private function statusOf(string $state, MigrationStage $stage): string
	{
		return match ($state)
		{
			MigrationOperationTable::STATE_WAITING => 'waiting',
			MigrationOperationTable::STATE_NEEDS_ATTENTION => 'blocked',
			MigrationOperationTable::STATE_CANCELLING => 'cancelling',
			MigrationOperationTable::STATE_DONE => 'done',
			MigrationOperationTable::STATE_CANCELLED => 'cancelled',
			default => $stage === MigrationStage::ReadyToSwitch ? 'switching' : 'running',
		};
	}

	private function reasonOf(array $operation, MigrationStage $stage): ?string
	{
		$error = (string)($operation['LAST_ERROR_CODE'] ?? '');
		if (in_array($error, [
			MigrationService::ERROR_MIGRATION_STOPPED,
			MigrationService::ERROR_FEATURE_DISABLED,
		], true))
		{
			return 'migration_paused';
		}

		if ((string)$operation['EXEC_STATE'] === MigrationOperationTable::STATE_NEEDS_ATTENTION)
		{
			return 'action_required';
		}

		if ($stage === MigrationStage::AwaitingSwitchAuthorization)
		{
			return 'waiting_for_switch_authorization';
		}

		return null;
	}

	private function nullableString(mixed $value): ?string
	{
		$value = trim((string)$value);

		return $value === '' ? null : $value;
	}
}
