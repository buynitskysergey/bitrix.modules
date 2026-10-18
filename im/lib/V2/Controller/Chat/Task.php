<?php

namespace Bitrix\Im\V2\Controller\Chat;

use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Controller\BaseController;
use Bitrix\Im\V2\Integration\AI\Restriction;
use Bitrix\Im\V2\Integration\AI\TaskCreation\CreationError;
use Bitrix\Im\V2\Integration\AI\TaskCreation\CreatorFactory;
use Bitrix\Im\V2\Link\Task\TaskService;
use Bitrix\Im\V2\Message\MessageError;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Im\V2\Rest\RestError;
use Bitrix\Main\Engine\AutoWire\ExactParameter;

class Task extends BaseController
{
	public function getAutoWiredParameters()
	{
		return array_merge([
			new ExactParameter(
				MessageCollection::class,
				'messages',
				function($className, array $ids) {
					return $this->getMessagesByIds($ids);
				}
			),
		], parent::getAutoWiredParameters());
	}

	/**
	 * @restMethod im.v2.Chat.Task.prepare
	 */
	public function prepareAction(?Chat $chat = null, ?\Bitrix\Im\V2\Message $message = null): ?array
	{
		if (isset($message)) {
			$chat = $message->getChat();
			if (!$chat->checkAccess()->isSuccess())
			{
				$this->addError(new RestError(RestError::ACCESS_ERROR));

				return null;
			}
		}

		if (!isset($chat))
		{
			$this->addError(new Chat\ChatError(Chat\ChatError::TASK_ACCESS_ERROR));

			return null;
		}

		$taskService = new TaskService();
		$result = $taskService->prepareDataForCreateSlider($chat, $message);
		if (!$result->isSuccess())
		{
			$error = $result->getErrors()[0];
			if (isset($error))
			{
				$this->addError($error);

				return null;
			}
		}

		return [
			'link' => $result->getResult()['LINK'],
			'params' => $result->getResult()['PARAMS']
		];
	}

	/**
	 * @restMethod im.v2.Chat.Task.createByAi
	 */
	public function createByAiAction(?MessageCollection $messages): ?array
	{
		if (!Features::isAiTaskCreationFromMessagesAvailable())
		{
			$this->addError(new CreationError(CreationError::AI_TASK_CREATION_NOT_AVAILABLE));

			return null;
		}

		if (!(new Restriction())->isAiTaskCreationActive())
		{
			$this->addError(new CreationError(CreationError::AI_TASK_CREATION_NOT_AVAILABLE));

			return null;
		}

		if ($messages === null || $messages->isEmpty())
		{
			$this->addError(new MessageError(MessageError::EMPTY_MESSAGE));

			return null;
		}

		$taskCreator = (new CreatorFactory())->createByMessages($messages);
		$result = $taskCreator->sendAiQuery();
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return ['result' => true];
	}
}
