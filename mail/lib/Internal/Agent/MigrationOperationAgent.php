<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Agent;

use Bitrix\Mail\Internal\Service\SourceGeneration\MigrationCancelService;
use Bitrix\Mail\Internal\Service\SourceGeneration\MigrationMetrics;
use Bitrix\Mail\Internal\Service\SourceGeneration\MigrationService;
use Bitrix\Mail\Internal\Service\SourceGeneration\MigrationStage;
use Bitrix\Mail\Internal\Service\SourceGeneration\MigrationUserStatusPublisher;
use Bitrix\Mail\Internal\Service\SourceGeneration\OperationTracker;
use Bitrix\Mail\Internal\Service\SourceGeneration\RetryPolicy;
use Bitrix\Mail\Integration\MailService\MigrationStatusProvider;
use Bitrix\Mail\Internals\MigrationOperationTable;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

final class MigrationOperationAgent
{
	public const BATCH_SIZE = 20;
	public const TIME_BUDGET_SECONDS = 60;
	public const PERIOD_SECONDS = 60;

	public function __construct(
		private readonly OperationTracker $tracker = new OperationTracker(),
		private readonly MigrationService $migrationService = new MigrationService(),
		private readonly MigrationCancelService $cancelService = new MigrationCancelService(),
		private readonly RetryPolicy $retryPolicy = new RetryPolicy(),
		private readonly MigrationStatusProvider $statusProvider = new MigrationStatusProvider(),
		private readonly MigrationUserStatusPublisher $statusPublisher = new MigrationUserStatusPublisher(),
		private readonly ?DateTime $fixedNow = null,
	)
	{
	}

	public static function run(): string
	{
		(new self())->execute();

		return self::class . '::run();';
	}

	public function execute(): void
	{
		$startedAt = microtime(true);
		try
		{
			$pendingPublications = $this->tracker->pendingPublications(self::BATCH_SIZE);
		}
		catch (\Throwable)
		{
			MigrationMetrics::journalOperation(
				'publication scheduler failed',
				0,
				'-',
				0,
				null,
				RetryPolicy::ERROR_SCHEDULER_EXCEPTION,
			);
			$pendingPublications = [];
		}
		foreach ($pendingPublications as $operation)
		{
			$this->flushPublication($operation);
		}

		try
		{
			$ready = $this->tracker->ready(self::BATCH_SIZE, $this->now());
		}
		catch (\Throwable)
		{
			MigrationMetrics::journalOperation(
				'operation scheduler failed',
				0,
				'-',
				0,
				null,
				RetryPolicy::ERROR_SCHEDULER_EXCEPTION,
			);

			return;
		}

		foreach ($ready as $operation)
		{
			if (microtime(true) - $startedAt >= self::TIME_BUDGET_SECONDS)
			{
				break;
			}

			$this->process($operation);
		}
	}

	private function process(array $operation): void
	{
		$publicStatusBeforeClaim = $this->publicStatusOf($operation);
		try
		{
			$operation = $this->tracker->claim($operation, $this->now());
		}
		catch (\Throwable)
		{
			$this->journalSchedulerFailure($operation, 'operation claim failed');

			return;
		}
		if ($operation === null)
		{
			return;
		}

		$mailboxId = (int)$operation['MAILBOX_ID'];
		$operationId = (string)$operation['OPERATION_ID'];
		$stage = MigrationStage::tryFrom((string)$operation['STAGE']) ?? MigrationStage::Legacy;

		if ((string)$operation['EXEC_STATE'] === MigrationOperationTable::STATE_CANCELLING)
		{
			$this->processCancellation($operation, $stage, $publicStatusBeforeClaim);

			return;
		}

		MigrationMetrics::journalOperation(
			'agent pass started',
			$mailboxId,
			$operationId,
			(int)$operation['GENERATION_ID'],
			$stage->value,
		);

		try
		{
			$outcome = $this->migrationService->run($mailboxId, $operationId);
		}
		catch (\Throwable)
		{
			$outcome = new Result();
			$outcome->addError(new \Bitrix\Main\Error(
				'The migration agent pass failed',
				RetryPolicy::ERROR_AGENT_EXCEPTION,
			));
		}

		$this->recordPass($operation, $outcome, $stage, $publicStatusBeforeClaim);
	}

	private function processCancellation(
		array $operation,
		MigrationStage $stage,
		?array $publicStatusBeforeClaim,
	): void
	{
		try
		{
			$outcome = $this->cancelService->step(
				(int)$operation['MAILBOX_ID'],
				(string)$operation['OPERATION_ID'],
			);
		}
		catch (\Throwable)
		{
			$outcome = new Result();
			$outcome->addError(new \Bitrix\Main\Error(
				'The migration cancellation pass failed',
				RetryPolicy::ERROR_AGENT_EXCEPTION,
			));
		}

		$outcomeStage = $outcome->getData()['stage'] ?? $stage;
		if (!$outcomeStage instanceof MigrationStage)
		{
			$outcomeStage = $stage;
		}

		if ($outcome->isSuccess() && (bool)($outcome->getData()['completed'] ?? false))
		{
			try
			{
				$stored = $this->tracker->markCancelled(
					(int)$operation['MAILBOX_ID'],
					(string)$operation['OPERATION_ID'],
					$outcomeStage,
					publish: true,
					notify: true,
				);
			}
			catch (\Throwable)
			{
				$stored = false;
			}
			if (!$stored)
			{
				$this->journalSchedulerFailure($operation, 'cancelled state was not stored');

				return;
			}
			$this->flushPublication($operation);
			MigrationMetrics::journalOperation(
				'cancelled',
				(int)$operation['MAILBOX_ID'],
				(string)$operation['OPERATION_ID'],
				(int)$operation['GENERATION_ID'],
				$outcomeStage->value,
			);

			return;
		}

		$errorCode = $this->firstErrorCode($outcome);
		$decision = $this->retryPolicy->decide(
			$operation,
			$outcomeStage,
			$errorCode,
			(bool)($outcome->getData()['progressed'] ?? false),
			$this->now(),
		);
		if ($decision['execState'] !== MigrationOperationTable::STATE_NEEDS_ATTENTION)
		{
			$decision['execState'] = MigrationOperationTable::STATE_CANCELLING;
		}

		$publish = $this->publicStatusAfterDecision($operation, $decision) !== $publicStatusBeforeClaim;
		if (!$this->storeOutcome($operation, $decision, $publish))
		{
			return;
		}
		if ($publish)
		{
			$this->flushPublication($operation);
		}
		MigrationMetrics::journalOperation(
			'cancellation pass completed',
			(int)$operation['MAILBOX_ID'],
			(string)$operation['OPERATION_ID'],
			(int)$operation['GENERATION_ID'],
			$outcomeStage->value,
			$errorCode,
		);
	}

	private function recordPass(
		array $operation,
		Result $outcome,
		MigrationStage $fallbackStage,
		?array $publicStatusBeforeClaim,
	): void
	{
		$stage = $outcome->getData()['stage'] ?? $fallbackStage;
		if (!$stage instanceof MigrationStage)
		{
			$stage = $fallbackStage;
		}

		$decision = $this->retryPolicy->decide(
			$operation,
			$stage,
			$this->firstErrorCode($outcome),
			(bool)($outcome->getData()['progressed'] ?? false),
			$this->now(),
		);

		$publish = $this->publicStatusAfterDecision($operation, $decision) !== $publicStatusBeforeClaim;
		if (!$this->storeOutcome(
			$operation,
			$decision,
			$publish,
			$decision['execState'] === MigrationOperationTable::STATE_DONE,
		))
		{
			return;
		}
		if ($publish)
		{
			$this->flushPublication($operation);
		}
		MigrationMetrics::journalOperation(
			$decision['execState'] === MigrationOperationTable::STATE_DONE
				? 'completed'
				: 'agent pass completed',
			(int)$operation['MAILBOX_ID'],
			(string)$operation['OPERATION_ID'],
			(int)$operation['GENERATION_ID'],
			$stage->value,
			$decision['errorCode'],
		);
	}

	private function firstErrorCode(Result $result): ?string
	{
		$error = $result->getErrors()[0] ?? null;

		return $error === null ? null : (string)$error->getCode();
	}

	private function publicStatusOf(array $operation): ?array
	{
		return $this->statusProvider->fromRow($operation)?->toPublicArray();
	}

	private function publicStatusAfterDecision(array $operation, array $decision): ?array
	{
		return $this->publicStatusOf(array_replace($operation, [
			'EXEC_STATE' => $decision['execState'],
			'STAGE' => $decision['stage']->value,
			'AUTO_RETRY' => $decision['autoRetry'] ? 'Y' : 'N',
			'STALLED_ATTEMPTS' => $decision['stalledAttempts'],
			'NEXT_RUN' => $decision['nextRun'],
			'LAST_PROGRESS' => $decision['lastProgress'],
			'LAST_ERROR_CODE' => $decision['errorCode'],
			'ERROR_OWNER' => $decision['errorOwner'],
		]));
	}

	private function storeOutcome(
		array $operation,
		array $decision,
		bool $publish,
		bool $notify = false,
	): bool
	{
		try
		{
			$stored = $this->tracker->recordOutcome($operation, $decision, $publish, $notify);
		}
		catch (\Throwable)
		{
			$stored = false;
		}

		if (!$stored)
		{
			$this->journalSchedulerFailure($operation, 'operation outcome was not stored');
		}

		return $stored;
	}

	private function flushPublication(array $operation): void
	{
		try
		{
			$published = $this->tracker->publishPending(
				(int)$operation['MAILBOX_ID'],
				(string)$operation['OPERATION_ID'],
				$this->statusPublisher,
			);
		}
		catch (\Throwable)
		{
			$published = false;
		}

		if (!$published)
		{
			$this->journalSchedulerFailure($operation, 'status publication failed');
		}
	}

	private function journalSchedulerFailure(array $operation, string $event): void
	{
		MigrationMetrics::journalOperation(
			$event,
			(int)($operation['MAILBOX_ID'] ?? 0),
			(string)($operation['OPERATION_ID'] ?? '-'),
			(int)($operation['GENERATION_ID'] ?? 0),
			(string)($operation['STAGE'] ?? MigrationStage::Legacy->value),
			RetryPolicy::ERROR_SCHEDULER_EXCEPTION,
		);
	}

	private function now(): DateTime
	{
		return $this->fixedNow === null ? new DateTime() : clone $this->fixedNow;
	}
}
