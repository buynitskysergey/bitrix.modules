<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AI\TaskCreation;

use Bitrix\AI\Context;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Result;
use Bitrix\Main\Application;

class SingleMessageCreator extends AbstractCreator
{
	public const CONTEXT_ID = 'chat_task_creation_from_single_message';
	private const LOCK_TIMEOUT = 3;

	public function __construct(Message $message)
	{
		$this->message = $message;
	}

	public function sendAiQuery(): Result
	{
		$result = new Result();

		if (!$this->canSendQuery())
		{
			return $result;
		}

		$messageId = (int)$this->message->getId();
		$userId = $this->getContext()->getUserId();
		$sourceText = $this->extractSourceText();
		$queueManager = AutoTaskQueueManager::getInstance();

		if (!Application::getConnection()->lock($this->getLockName($messageId), self::LOCK_TIMEOUT))
		{
			// a concurrent attempt for the same message holds the lock - let it finish
			return $result;
		}

		try
		{
			if ($queueManager->inQueue([$messageId]))
			{
				return $result;
			}

			$queueManager->add([$messageId]);
		}
		finally
		{
			Application::getConnection()->unlock($this->getLockName($messageId));
		}

		if ($sourceText === '')
		{
			try
			{
				$this->createMinimalTask($userId);
			}
			finally
			{
				$queueManager->delete([$messageId]);
			}

			return $result;
		}

		$result = $this->sendAiQueryInternal($userId, $sourceText);
		if (!$result->isSuccess())
		{
			$queueManager->delete([$messageId]);
		}

		return $result;
	}

	private function getLockName(int $messageId): string
	{
		return "im_auto_task_creation_single_message_{$messageId}";
	}

	private function sendAiQueryInternal(int $userId, string $sourceText): Result
	{
		$context = new Context(self::MODULE_ID, self::CONTEXT_ID);
		$context->setParameters([
			'messageId' => (int)$this->message->getId(),
			'userId' => $userId,
		]);

		return $this->dispatchTaskExtractionQuery($context, $sourceText, $userId);
	}

	public function extractSourceText(): string
	{
		return self::extractSourceTextFrom($this->message);
	}

	public function isMessageDeleted(): bool
	{
		return $this->isDeletedMessage($this->message);
	}

	protected function hasMinimalTaskContent(): bool
	{
		return $this->messageHasMinimalTaskContent($this->message);
	}

	protected function canSendQuery(): bool
	{
		if ($this->isEmptyMessage() || $this->isContentlessMessage($this->message))
		{
			return false;
		}

		if (
			$this->extractSourceText() === ''
			&& !$this->hasMinimalTaskContent()
		)
		{
			return false;
		}

		return !$this->isDeniedChatForAutoTask();
	}

	protected function getMinimalTaskTitle(): string
	{
		return $this->getFileBasedTitle($this->message) ?? $this->getDefaultTaskTitle();
	}
}
