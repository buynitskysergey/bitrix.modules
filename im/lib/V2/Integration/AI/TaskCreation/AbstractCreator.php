<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AI\TaskCreation;

use Bitrix\AI\Context;
use Bitrix\AI\Engine;
use Bitrix\AI\Payload\Prompt;
use Bitrix\AI\Quality;
use Bitrix\AI\Tuning\Defaults;
use Bitrix\Im\V2\Chat\OpenLineChat;
use Bitrix\Im\V2\Chat\OpenLineLiveChat;
use Bitrix\Im\V2\Common\ContextCustomer;
use Bitrix\Im\V2\Entity\User\User;
use Bitrix\Im\V2\Integration\AI\CopilotError;
use Bitrix\Im\V2\Integration\AI\UserKeyConverter;
use Bitrix\Im\V2\Integration\Tasks\Service\Transcription\TranscriptionHandler;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\BlocksBuilder\BuilderService;
use Bitrix\Im\V2\Message\Params;
use Bitrix\Im\V2\Pull\Event\AutoTaskStatus;
use Bitrix\Im\V2\Result;
use Bitrix\Im\V2\Service\Locator;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\DateTime;

abstract class AbstractCreator
{
	use ContextCustomer;

	protected const MODULE_ID = 'im';
	protected const PROMPT_CODE = 'task_extraction_prompt';
	protected const DATE_FORMAT = 'Y/m/d H:i';
	protected const MAX_FALLBACK_TITLE_LENGTH = 100;

	protected Message $message;

	abstract public function sendAiQuery(): Result;

	public function getMessage(): Message
	{
		return $this->message;
	}

	public function createTask(array $data, string $sourceText, int $userId): void
	{
		if ($this->isEmptyMessage())
		{
			return;
		}

		Locator::getContext()->setUser($userId);
		$transcriptionHandler = ServiceLocator::getInstance()->get(TranscriptionHandler::class);
		$transcriptionHandler->handle($data, $this->message, $sourceText, $userId);
	}

	protected function isEmptyMessage(): bool
	{
		return $this->message->getMessageId() === null || $this->message->getChatId() === null;
	}

	protected function isTaskChat(): bool
	{
		return $this->message->getChat()->getEntityType() === 'TASKS_TASK';
	}

	protected function convertBoolToInt(bool $value): int
	{
		return $value ? 1 : 0;
	}

	protected function getCurrentTime(): string
	{
		$userTimeZone = User::getInstance($this->getContext()->getUserId())->getTimezone();
		$timeZoneOffset = \CTimeZone::getTimezoneOffset($userTimeZone);
		$dateTime = DateTime::createFromTimestamp((new DateTime())->getTimestamp() + $timeZoneOffset);

		return $dateTime->format(self::DATE_FORMAT);
	}

	protected function getChatUsers(bool $anonymizeUserFormat = true): string
	{
		$result = [];
		$userIds = $this->message->getChat()->getRelations()->getUserIds();
		$userKeyConverter = new UserKeyConverter((int)$this->message->getChatId());

		foreach ($userIds as $userId)
		{
			$result[] = $anonymizeUserFormat
				? $userKeyConverter->getAnonymizedUserKey($userId)
				: $userKeyConverter->getUserKey($userId)
			;
		}

		return implode(', ', $result);
	}

	protected function getEngine(Context $context): ?Engine
	{
		$availableEngines = Defaults::getProviderSelectFieldParams(
			'text',
			new Quality(Quality::QUALITIES['chat_task_creation_from_transcription'])
		);

		return Engine::getByCode($availableEngines['default'] ?? '', $context);
	}

	protected function dispatchTaskExtractionQuery(Context $context, string $sourceText, int $userId): Result
	{
		$result = new Result();

		$engine = $this->getEngine($context);
		if ($engine === null)
		{
			return $result->addError(new CopilotError(CopilotError::ENGINE_NOT_FOUND));
		}

		$engine
			->setPayload((new Prompt(self::PROMPT_CODE))->setMarkers([
				'transcript' => $sourceText,
				'chat_users' => $this->getChatUsers($engine->requiresPersonalDataObfuscation()),
				'date_time' => $this->getCurrentTime(),
				'is_task_chat' => $this->convertBoolToInt($this->isTaskChat()),
				'anonymize_user_format' => $this->convertBoolToInt($engine->requiresPersonalDataObfuscation()),
			]))
			->setResponseJsonMode(true)
			->onError(static function (Error $processingError) use (&$result) {
				$result->addError($processingError);
			})
			->completionsInQueue()
		;

		if (!$result->isSuccess())
		{
			return $result;
		}

		(new AutoTaskStatus($this->message, $userId, Status::Search))->send();

		return $result;
	}

	protected function isDeniedChatForAutoTask(): bool
	{
		$chat = $this->message->getChat();

		return $chat instanceof OpenLineChat
			|| $chat instanceof OpenLineLiveChat
			|| $chat->getEntityType() === 'SUPPORT24_QUESTION' /** @see \Bitrix\ImBot\Bot\Support24::CHAT_ENTITY_TYPE */
			|| $chat->getEntityType() === 'NETWORK_DIALOG' /** @see \Bitrix\ImBot\Bot\Network::CHAT_ENTITY_TYPE */
		;
	}

	/**
	 * System messages, stickers and soft-deleted messages carry no meaningful task content.
	 */
	protected function isContentlessMessage(Message $message): bool
	{
		return $message->isSystem()
			|| $message->getParams()->isSet(Params::STICKER_PARAMS)
			|| $this->isDeletedMessage($message)
		;
	}

	/**
	 * A soft-deleted message keeps its DB row but its text is replaced by the "message deleted"
	 * placeholder and IS_DELETED is set, so it must never feed that placeholder into the AI query
	 * or into the created task.
	 */
	protected function isDeletedMessage(Message $message): bool
	{
		return $message->getParams()->get(Params::IS_DELETED)->getValue() === true;
	}

	protected static function extractSourceTextFrom(Message $message): string
	{
		$plain = trim((string)$message->getMessage());
		if ($plain !== '')
		{
			return $plain;
		}

		$builder = $message->getBlocksBuilder();
		if ($builder !== null)
		{
			$blockText = trim($builder->getPayloadText());
			if ($blockText !== '' && $blockText !== BuilderService::getPlaceholder())
			{
				return $blockText;
			}
		}

		return '';
	}

	protected function getFileBasedTitle(Message $message): ?string
	{
		if (!$message->hasFiles())
		{
			return null;
		}

		$fileName = $message->getFiles()->getAny()?->getDiskFile()?->getName();
		if (is_string($fileName) && $fileName !== '')
		{
			return mb_substr($fileName, 0, self::MAX_FALLBACK_TITLE_LENGTH);
		}

		return null;
	}

	protected function getDefaultTaskTitle(): string
	{
		return Loc::getMessage('IM_AI_TASK_DEFAULT_TITLE') ?? '';
	}

	protected function hasMinimalTaskContent(): bool
	{
		return false;
	}

	protected function getMinimalTaskTitle(): string
	{
		return $this->getDefaultTaskTitle();
	}

	protected function messageHasMinimalTaskContent(Message $message): bool
	{
		$params = $message->getParams();

		return $message->hasFiles()
			|| $params->isSet(Params::ATTACH)
			|| $params->isSet(Params::KEYBOARD)
			|| $params->isSet(Params::MENU)
		;
	}

	protected function createMinimalTask(int $userId): void
	{
		if (!$this->hasMinimalTaskContent())
		{
			(new AutoTaskStatus($this->message, $userId, Status::NotFound))->send();

			return;
		}

		(new AutoTaskStatus($this->message, $userId, Status::Search))->send();

		$this->createTask(
			[
				'type' => 'task',
				'task_data' => [
					'title' => $this->getMinimalTaskTitle(),
				],
			],
			'',
			$userId,
		);
	}
}
