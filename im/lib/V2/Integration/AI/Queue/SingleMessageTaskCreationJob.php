<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AI\Queue;

use Bitrix\AI\Engine\IEngine;
use Bitrix\AI\Result;
use Bitrix\Im\V2\Integration\AI\TaskCreation\AutoTaskQueueManager;
use Bitrix\Im\V2\Integration\AI\TaskCreation\SingleMessageCreator;
use Bitrix\Im\V2\Integration\AI\TaskCreation\Status;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Pull\Event\AutoTaskStatus;

class SingleMessageTaskCreationJob extends QueueJob
{
	public function processQueueJob(): void
	{
		/** @var IEngine $engine */
		$engine = $this->event->getParameter('engine');
		$parameters = $engine->getContext()->getParameters();

		$messageId = (int)($parameters['messageId'] ?? 0);
		$userId = (int)($parameters['userId'] ?? 0);

		$queueManager = AutoTaskQueueManager::getInstance();

		$result = $this->event->getParameter('result');
		if (!($result instanceof Result))
		{
			$queueManager->delete([$messageId]);

			return;
		}

		$message = new Message($messageId);
		try
		{
			$creator = new SingleMessageCreator($message);

			// a hard-deleted source row drops the message/chat id; a soft-deleted message keeps the
			// row but replaces its text with the "message deleted" placeholder (IS_DELETED=Y). Both
			// are terminal: no task must be created from a missing or placeholder message.
			if ($message->getMessageId() === null || $message->getChatId() === null || $creator->isMessageDeleted())
			{
				if ($userId > 0)
				{
					(new AutoTaskStatus((new Message())->setMessageId($messageId), $userId, Status::NotFound))->send();
				}

				return;
			}

			$sourceText = $creator->extractSourceText();
			$data = $result->getJsonData() ?? [];

			$creator->createTask($data, $sourceText, $userId);
		}
		finally
		{
			$queueManager->delete([$messageId]);
		}
	}

	public function processFailedJob(): void
	{
		/** @var IEngine $engine */
		$engine = $this->event->getParameter('engine');
		$parameters = $engine->getContext()->getParameters();

		$messageId = (int)($parameters['messageId'] ?? 0);
		$userId = (int)($parameters['userId'] ?? 0);

		AutoTaskQueueManager::getInstance()->delete([$messageId]);

		if ($messageId <= 0 || $userId <= 0)
		{
			return;
		}

		$message = new Message($messageId);

		(new AutoTaskStatus($message, $userId, Status::NotFound))->send();
	}
}
