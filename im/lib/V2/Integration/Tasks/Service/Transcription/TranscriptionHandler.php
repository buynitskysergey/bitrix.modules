<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Tasks\Service\Transcription;

use Bitrix\Im\V2\Integration\AI\TaskCreation\Status;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Pull\Event\AutoTaskStatus;

class TranscriptionHandler
{
	private const TYPE_FIELD = 'type';
	private const TASK_DATA_FIELD = 'task_data';
	private const RESULT_DATA_FIELD = 'result_data';

	public function __construct(
		private readonly TranscribedTaskHandler $taskHandler,
		private readonly TranscribedResultHandler $resultHandler,
	)
	{
	}

	public function handle(array $data, Message $message, string $transcribedText, int $initiatorId): bool
	{
		$type = EntityType::tryFrom($data[self::TYPE_FIELD] ?? '');

		return match ($type)
		{
			EntityType::Task => $this->processTask($data, $message, $transcribedText, $initiatorId),
			EntityType::Result => $this->processResult($data, $message, $initiatorId),
			EntityType::Unknown => $this->processUnknown($message, $initiatorId),
			default => $this->processError($message, $initiatorId),
		};
	}

	private function processTask(array $data, Message $message, string $transcribedText, int $initiatorId): bool
	{
		$taskData = $data[self::TASK_DATA_FIELD] ?? [];
		if (!is_array($taskData))
		{
			return false;
		}

		(new AutoTaskStatus($message, $initiatorId, Status::TaskCreationStarted, true))->send();

		return $this->taskHandler->handle($taskData, $message, $transcribedText, $initiatorId);
	}

	private function processResult(array $data, Message $message, int $initiatorId): bool
	{
		$resultData = $data[self::RESULT_DATA_FIELD] ?? '';
		if (!is_string($resultData))
		{
			return false;
		}

		(new AutoTaskStatus($message, $initiatorId, Status::ResultCreationStarted, true))->send();

		return $this->resultHandler->handle($resultData, $message, $initiatorId);
	}

	private function processUnknown(Message $message, int $initiatorId): bool
	{
		(new AutoTaskStatus($message, $initiatorId, Status::NotFound))->send();

		return true;
	}

	private function processError(Message $message, int $initiatorId): bool
	{
		(new AutoTaskStatus($message, $initiatorId, Status::NotFound))->send();

		return false;
	}
}
