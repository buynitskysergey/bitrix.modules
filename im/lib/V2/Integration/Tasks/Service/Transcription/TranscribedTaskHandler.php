<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Tasks\Service\Transcription;

use Bitrix\Im\V2\Integration\Tasks\Service\ChatTaskLinkService;
use Bitrix\Im\V2\Integration\Tasks\Service\CheckListService;
use Bitrix\Im\V2\Integration\Tasks\Service\ForwardService;
use Bitrix\Im\V2\Integration\Tasks\Service\TaskService;
use Bitrix\Im\V2\Integration\Tasks\Service\Transcription\Mapper\CheckListMapper;
use Bitrix\Im\V2\Integration\Tasks\Service\Transcription\Mapper\TaskMapper;
use Bitrix\Im\V2\Link\Task\TaskType;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Main\Loader;
use Bitrix\Main\SystemException;
use Bitrix\Tasks\V2\FormV2Feature;
use Bitrix\Tasks\V2\Internal\Entity\Task;

class TranscribedTaskHandler
{
	public function __construct(
		private readonly TaskMapper $taskMapper,
		private readonly CheckListMapper $checkListMapper,
		private readonly TaskService $taskService,
		private readonly CheckListService $checkListService,
		private readonly ChatTaskLinkService $chatTaskLinkService,
		private readonly UnresolvedFieldsNotifier $unresolvedFieldsNotifier,
		private readonly ForwardService $forwardService,
	)
	{
	}

	/**
	 * @param MessageCollection|null $messagesToForward when set (multiselect flow), the whole
	 *        selection is forwarded to the task chat instead of the single source message
	 */
	public function handle(
		array $taskData,
		Message $message,
		string $transcribedText,
		int $initiatorId,
		?MessageCollection $messagesToForward = null,
	): bool
	{
		if (empty($taskData) || !Loader::includeModule('tasks'))
		{
			return false;
		}

		if ($initiatorId <= 0)
		{
			return false;
		}

		$taskToAdd = $this->taskMapper->convertFromTranscribedMessage(
			$taskData,
			$message,
			$transcribedText,
			$initiatorId,
			$messagesToForward !== null,
		);
		if ($taskToAdd === null)
		{
			return false;
		}

		try
		{
			$addedTask = $this->taskService->add($taskToAdd, $initiatorId);

			$checkListsToAdd = $this->checkListMapper->convertFromTranscribedMessage($taskData['checklists'] ?? []);

			if (!empty($checkListsToAdd))
			{
				$this->checkListService->add($addedTask, $checkListsToAdd, $initiatorId);
			}
		}
		catch (SystemException)
		{
			return false;
		}

		$this->chatTaskLinkService->linkFromMessage(
			taskId: $addedTask->getId(),
			message: $message,
			userId: $initiatorId,
			taskType: $this->getTaskType($addedTask, $messagesToForward !== null),
		);

		$this->unresolvedFieldsNotifier->notify($addedTask, $taskData, $initiatorId);

		if (FormV2Feature::isOn())
		{
			$messagesToForward === null
				? $this->forwardService->forwardMessageToTask($message, $addedTask->getId(), $initiatorId)
				: $this->forwardService->forwardMessagesToTask($messagesToForward, $addedTask->getId(), $initiatorId);
		}

		return true;
	}

	private function getTaskType(Task $task, bool $isMultipleMessages): TaskType
	{
		// multiselect context wins over the anchor message type: the anchor can be
		// a voice/video note, but the task is still a multiple-messages auto task
		if ($isMultipleMessages)
		{
			return TaskType::MultipleMessagesAutoTask;
		}

		if ($task->scenarios?->contains(Task\Scenario::Voice))
		{
			return TaskType::VoiceNoteAutoTask;
		}

		if ($task->scenarios?->contains(Task\Scenario::Video))
		{
			return TaskType::VideoNoteAutoTask;
		}

		return TaskType::SingleMessageAutoTask;
	}
}
