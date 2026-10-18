<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AI\Queue;

use Bitrix\AI\Engine\IEngine;
use Bitrix\AI\Result;
use Bitrix\Im\V2\Integration\AI\TaskCreation\Status;
use Bitrix\Im\V2\Integration\AI\TaskCreation\TranscriptionCreator;
use Bitrix\Im\V2\Integration\AI\Transcription\TranscribeManager;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Pull\Event\AutoTaskStatus;

class TranscriptionTaskCreationJob extends QueueJob
{
	public function processQueueJob(): void
	{
		/** @var IEngine $engine */
		$engine = $this->event->getParameter('engine');
		$context = $engine->getContext();
		$parameters = $context->getParameters();

		$result = $this->event->getParameter('result');
		if (!($result instanceof Result))
		{
			return;
		}

		$fileId = (int)$parameters['fileId'];
		$diskFileId = (int)$parameters['diskFileId'];
		$messageId = (int)$parameters['messageId'];
		$userId = (int)$parameters['userId'];

		$message = new Message($messageId);
		if ($message->getMessageId() === null || $message->getChatId() === null)
		{
			// the source message was deleted before the AI response returned: error, no task
			if ($userId > 0)
			{
				(new AutoTaskStatus((new Message())->setMessageId($messageId), $userId, Status::NotFound))->send();
			}

			return;
		}

		$transcribeManager = new TranscribeManager($fileId, $diskFileId, (int)$message->getChatId(), $messageId);
		$transcriptText = $transcribeManager->getFileTranscription()?->getPlainText();
		$data = $result->getJsonData() ?? [];

		if (empty($transcriptText))
		{
			// transcription yielded no text: error, no task (override ERR-013)
			if ($userId > 0)
			{
				(new AutoTaskStatus($message, $userId, Status::NotFound))->send();
			}

			return;
		}

		(new TranscriptionCreator($message, $fileId, $diskFileId))->createTask($data, $transcriptText, $userId);
	}

	public function processFailedJob(): void
	{
		/** @var IEngine $engine */
		$engine = $this->event->getParameter('engine');
		$parameters = $engine->getContext()->getParameters();

		$messageId = (int)$parameters['messageId'];
		$userId = (int)$parameters['userId'];
		$message = new Message($messageId);

		(new AutoTaskStatus($message, $userId, Status::NotFound))->send();
	}
}
