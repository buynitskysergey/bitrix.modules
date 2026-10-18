<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AI\Queue;

use Bitrix\AI\Engine\IEngine;
use Bitrix\AI\Result;
use Bitrix\Im\V2\Integration\AI\TaskCreation\AutoTaskQueueManager;
use Bitrix\Im\V2\Integration\AI\TaskCreation\AutoTaskType;
use Bitrix\Im\V2\Integration\AI\TaskCreation\MultipleMessagesCreator;
use Bitrix\Im\V2\Integration\AI\TaskCreation\Status;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Im\V2\Pull\Event\AutoTaskStatus;

class MultipleMessageTaskCreationJob extends QueueJob
{
	public function processQueueJob(): void
	{
		/** @var IEngine $engine */
		$engine = $this->event->getParameter('engine');
		$parameters = $engine->getContext()->getParameters();

		$messageIds = $this->extractIntList($parameters, 'messageIds');
		if (empty($messageIds))
		{
			return;
		}

		$userId = (int)($parameters['userId'] ?? 0);
		$queueManager = AutoTaskQueueManager::getInstance();

		// the collection is needed on every branch (anchor resolution and forwarding) and is
		// loaded only once here, so the job and the live creator pick the same anchor message
		$messages = new MessageCollection($messageIds);

		$result = $this->event->getParameter('result');
		if (!($result instanceof Result))
		{
			$queueManager->delete($messageIds, AutoTaskType::TaskCreationMulti);
			$this->sendNotFound($messageIds, $messages, $userId);

			return;
		}

		try
		{
			if (!MultipleMessagesCreator::isSelectionComplete($messageIds, $messages))
			{
				// one of the selected messages was deleted before the AI response returned (ERR-015):
				// abort instead of building a task from the survivors
				$this->sendNotFound($messageIds, $messages, $userId);

				return;
			}

			$creator = new MultipleMessagesCreator($messages);
			// reuse the combined text the live creator already built (m3); fall back to a fresh
			// build only for records queued by older code without the sourceText parameter
			$sourceText = isset($parameters['sourceText']) && is_string($parameters['sourceText'])
				? $parameters['sourceText']
				: $creator->buildCombinedSourceText();
			$data = $result->getJsonData() ?? [];

			$creator->createTask($data, $sourceText, $userId);
		}
		finally
		{
			// the dedup record must not outlive the job, even when loading the collection
			// or building the combined text throws - a leaked record blocks the selection
			$queueManager->delete($messageIds, AutoTaskType::TaskCreationMulti);
		}
	}

	public function processFailedJob(): void
	{
		/** @var IEngine $engine */
		$engine = $this->event->getParameter('engine');
		$parameters = $engine->getContext()->getParameters();

		$messageIds = $this->extractIntList($parameters, 'messageIds');
		if (empty($messageIds))
		{
			return;
		}

		$userId = (int)($parameters['userId'] ?? 0);

		AutoTaskQueueManager::getInstance()->delete($messageIds, AutoTaskType::TaskCreationMulti);

		$this->sendNotFound($messageIds, new MessageCollection($messageIds), $userId);
	}

	private function sendNotFound(array $messageIds, MessageCollection $messages, int $userId): void
	{
		if (empty($messageIds) || $userId <= 0)
		{
			return;
		}

		// resolve the anchor from the actually loaded collection (prefers a surviving message),
		// so the job and the live creator report the terminal status on the same message id
		$anchorMessage = $messages->isEmpty()
			? new Message(MultipleMessagesCreator::resolveAnchorMessageId($messageIds))
			: MultipleMessagesCreator::resolveAnchorMessage($messages);

		(new AutoTaskStatus($anchorMessage, $userId, Status::NotFound))->send();
	}
}
