<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AI\TaskCreation;

use Bitrix\AI\Context;
use Bitrix\AI\Payload\Prompt;
use Bitrix\Im\V2\Integration\AI\CopilotError;
use Bitrix\Im\V2\Integration\AI\Transcription\LaunchType;
use Bitrix\Im\V2\Integration\AI\Transcription\TranscribeManager;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Pull\Event\AutoTaskStatus;
use Bitrix\Im\V2\Result;
use Bitrix\Main\Error;

class TranscriptionCreator extends AbstractCreator
{
	public const CONTEXT_ID = 'chat_task_creation_from_transcription';

	protected int $fileId;
	protected int $diskFileId;

	public function __construct(Message $message, int $fileId, int $diskFileId)
	{
		$this->message = $message;
		$this->fileId = $fileId;
		$this->diskFileId = $diskFileId;
	}

	public function sendAiQuery(): Result
	{
		$file = $this->message->getFiles()->getAny();
		$transcriptText = $file->getCompletedTranscription()?->transcriptText;

		if ($transcriptText !== null)
		{
			return $this->sendAiQueryInternal($transcriptText, $this->getContext()->getUserId(), false);
		}

		if ($file->isTranscribable())
		{
			$transcribeManager = new TranscribeManager(
				$file->getOriginalFileId(),
				$file->getId(),
				$this->message->getChatId(),
				$this->message->getMessageId()
			);

			return $transcribeManager->transcribeFile(
				LaunchType::Manual,
				[
					'isAutoTask' => false,
					'userId' => $this->getContext()->getUserId()
				]
			);
		}

		return (new Result())->addError(new CopilotError(CopilotError::TRANSCRIPTION_SERVICE_ERROR));
	}

	public function sendAiQueryInternal(string $transcriptText, int $userId, bool $isAutoTask = true): Result
	{
		$result = new Result();

		if (!$this->canSendQuery($isAutoTask))
		{
			return $result;
		}

		$contextParams = [
			'fileId' => $this->fileId,
			'diskFileId' => $this->diskFileId,
			'messageId' => (int)$this->message->getId(),
			'isAutoTask' => $isAutoTask,
			'userId' => $userId,
		];

		$context = new Context(self::MODULE_ID, self::CONTEXT_ID);
		$context->setParameters($contextParams);

		$engine = $this->getEngine($context);
		if ($engine)
		{
			$engine
				->setPayload((new Prompt(self::PROMPT_CODE))->setMarkers([
					'transcript' => $transcriptText,
					'chat_users' => $this->getChatUsers($engine->requiresPersonalDataObfuscation()),
					'date_time' => $this->getCurrentTime(),
					'is_task_chat' => $this->convertBoolToInt($this->isTaskChat()),
					'anonymize_user_format' => $this->convertBoolToInt($engine->requiresPersonalDataObfuscation()),
				]))
				->setResponseJsonMode(true)
				->onError(function (Error $processingError) use(&$result) {
					$result->addError($processingError);
				})
				->completionsInQueue()
			;
		}

		(new AutoTaskStatus($this->message, $userId, Status::Search))->send();

		return $result;
	}

	protected function canSendQuery(bool $isAutoTask): bool
	{
		if ($isAutoTask && $this->message->getParams()->isSet(Message\Params::FORWARD_ID))
		{
			return false;
		}

		if ($this->isEmptyMessage())
		{
			return false;
		}

		return !$this->isDeniedChatForAutoTask();
	}
}
