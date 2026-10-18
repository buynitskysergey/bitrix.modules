<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Completion;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\V2\Internal\Access\Service\TaskAccessService;
use Bitrix\Tasks\V2\Internal\Access\Task\Status\CompletionAccessRule;
use Bitrix\Tasks\V2\Internal\Repository\TaskStatusRepository;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Config\UpdateConfig;
use Bitrix\Tasks\V2\Public\Command\Task\Status\CompleteTaskCommand;

class TaskCompletionService
{
	public function __construct(
		private readonly CheckListCompletionService $checkListCompletionService,
		private readonly TaskStatusRepository $taskStatusRepository,
		private readonly TaskAccessService $taskAccessService,
	)
	{
	}

	public function complete(int $taskId, UpdateConfig $config, bool $withCheckList = true): Result
	{
		$result = new Result();
		$task = $this->taskStatusRepository->getById($taskId);

		if ($task === null)
		{
			return $result->addError(new Error('Task not found', 'TASK_NOT_FOUND'));
		}

		$userId = $config->getUserId();

		if ($task->status?->isCompleted() === true)
		{
			if (!$this->taskAccessService->can($userId, ActionDictionary::ACTION_TASK_READ, $taskId))
			{
				return $result->addError($this->getAccessError());
			}

			return $result;
		}

		if (!$this->canCompleteWithChecklist($taskId, $userId))
		{
			return $result->addError($this->getAccessError());
		}

		if ($withCheckList)
		{
			$checkListResult = $this->checkListCompletionService->completeForTask($taskId, $userId);
			if (!$checkListResult->isSuccess())
			{
				return $result->addErrors($checkListResult->getErrors());
			}
		}

		$commandResult = $this->runCompleteTaskCommand($taskId, $config);
		if (!$commandResult->isSuccess())
		{
			return $result->addErrors($commandResult->getErrors());
		}

		return $result;
	}

	public function canCompleteWithChecklist(int $taskId, int $userId): bool
	{
		return CompletionAccessRule::isSatisfiedBy(
			fn (string $action): bool => $this->taskAccessService->can($userId, $action, $taskId),
		);
	}

	public function hasFinalStatus(int $taskId): bool
	{
		return $this->taskStatusRepository->getById($taskId)?->status?->isCompleted() === true;
	}

	protected function runCompleteTaskCommand(int $taskId, UpdateConfig $config): Result
	{
		return (new CompleteTaskCommand(
			taskId: $taskId,
			config: $config,
		))->run();
	}

	private function getAccessError(): Error
	{
		return $this->taskAccessService->getUserError() ?? new Error('Access denied', 'ACCESS_DENIED');
	}
}
