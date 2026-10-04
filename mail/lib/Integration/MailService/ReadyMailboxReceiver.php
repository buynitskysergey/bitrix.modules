<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\MailService;

use Bitrix\Mail\Internal\Service\SourceGeneration\ConnectionSnapshot;
use Bitrix\Mail\Internal\Service\SourceGeneration\ConnectionSnapshotValidator;
use Bitrix\Mail\Internal\Service\SourceGeneration\MigrationService;
use Bitrix\Mail\Internal\Service\SourceGeneration\MigrationStage;
use Bitrix\Mail\Internal\Service\SourceGeneration\MigrationMetrics;
use Bitrix\Mail\Internal\Service\SourceGeneration\OperationTracker;
use Bitrix\Mail\Internal\Service\SourceGeneration\RetryPolicy;
use Bitrix\Mail\Internals\MailboxSourceGenerationTable;
use Bitrix\Mail\Internals\MigrationOperationTable;
use Bitrix\Mail\MailboxTable;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class ReadyMailboxReceiver
{
	public const ERROR_CONTRACT_INVALID = 'MAIL_READY_MAILBOX_CONTRACT_INVALID';
	public const ERROR_MAILBOX_NOT_FOUND = 'MAIL_READY_MAILBOX_NOT_FOUND';

	private const OPERATION_ID_MAX_LENGTH = 64;

	public function __construct(
		private readonly MigrationService $migrationService = new MigrationService(),
		private readonly ConnectionSnapshotValidator $snapshotValidator = new ConnectionSnapshotValidator(),
		private readonly OperationTracker $operationTracker = new OperationTracker(),
	)
	{
	}

	public function accept(ReadyMailbox $readyMailbox): Result
	{
		$operationId = trim($readyMailbox->operationId());
		$mailboxId = $readyMailbox->mailboxId();

		if (
			$mailboxId <= 0
			|| $operationId === ''
			|| mb_strlen($operationId) > self::OPERATION_ID_MAX_LENGTH
		)
		{
			return RetryPolicy::enrichCommandResult((new Result())
				->setData(['stage' => MigrationStage::Legacy])
				->addError(new Error('A valid ready mailbox contract is expected', self::ERROR_CONTRACT_INVALID))
			);
		}

		$mailboxExists = (bool)MailboxTable::getList([
			'select' => ['ID'],
			'filter' => ['=ID' => $mailboxId],
			'limit' => 1,
		])->fetch();

		if (!$mailboxExists)
		{
			return RetryPolicy::enrichCommandResult((new Result())
				->setData(['stage' => MigrationStage::Legacy])
				->addError(new Error('The mailbox is not found', self::ERROR_MAILBOX_NOT_FOUND))
			);
		}

		$knownOperation = $this->operationTracker->find($mailboxId, $operationId);
		if ($knownOperation !== null)
		{
			$knownStage = MigrationStage::tryFrom((string)$knownOperation['STAGE']) ?? MigrationStage::Legacy;
			if ((string)$knownOperation['EXEC_STATE'] === MigrationOperationTable::STATE_CANCELLED)
			{
				return RetryPolicy::enrichCommandResult(
					(new Result())->setData(['stage' => $knownStage]),
				);
			}

			if (
				(string)$knownOperation['CANCEL_REQUESTED'] === 'Y'
				|| (string)$knownOperation['EXEC_STATE'] === MigrationOperationTable::STATE_CANCELLING
			)
			{
				return RetryPolicy::enrichCommandResult((new Result())
					->setData(['stage' => $knownStage])
					->addError(new Error(
						'The migration operation is being cancelled',
						MigrationService::ERROR_CANCEL_REQUESTED,
					))
				);
			}
		}

		$validated = $this->snapshotValidator->validate($readyMailbox->connectionSnapshot());
		if (!$validated->isSuccess())
		{
			return RetryPolicy::enrichCommandResult($validated->setData(['stage' => MigrationStage::Legacy]));
		}

		$snapshot = $validated->getData()[ConnectionSnapshotValidator::RESULT_SNAPSHOT] ?? null;
		if (!$snapshot instanceof ConnectionSnapshot)
		{
			return RetryPolicy::enrichCommandResult((new Result())
				->setData(['stage' => MigrationStage::Legacy])
				->addError(new Error('A valid ready mailbox contract is expected', self::ERROR_CONTRACT_INVALID))
			);
		}

		$result = $this->migrationService->acceptSnapshot(
			$mailboxId,
			$operationId,
			$snapshot,
			trim($readyMailbox->migratorService()),
		);
		if (!$result->isSuccess())
		{
			return RetryPolicy::enrichCommandResult($result);
		}

		$generation = MailboxSourceGenerationTable::getList([
			'select' => ['ID'],
			'filter' => [
				'=MAILBOX_ID' => $mailboxId,
				'=OPERATION_ID' => $operationId,
			],
			'limit' => 1,
		])->fetch();
		$stage = $result->getData()['stage'] ?? MigrationStage::Legacy;

		if (
			!$stage instanceof MigrationStage
			|| !$generation
			|| !$this->operationTracker->acceptSnapshot(
				$mailboxId,
				$operationId,
				(int)$generation['ID'],
				$stage,
				trim($readyMailbox->migratorService()),
			)
		)
		{
			$result->addError(new Error(
				'The migration operation scheduling snapshot is not saved',
				MigrationService::ERROR_SNAPSHOT_NOT_SAVED,
			));
		}
		else
		{
			MigrationMetrics::journalOperation(
				'snapshot accepted',
				$mailboxId,
				$operationId,
				(int)$generation['ID'],
				$stage->value,
			);
		}

		return RetryPolicy::enrichCommandResult($result);
	}

}
