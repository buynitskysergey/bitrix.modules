<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AI\TaskCreation;

use Bitrix\AI\Context;
use Bitrix\Im\V2\Entity\File\FileItem;
use Bitrix\Im\V2\Integration\AI\CopilotError;
use Bitrix\Im\V2\Integration\AI\Transcription\LaunchType;
use Bitrix\Im\V2\Integration\AI\Transcription\TranscribeManager;
use Bitrix\Im\V2\Integration\Tasks\Service\Transcription\MultipleMessagesTaskHandler;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Im\V2\Pull\Event\AutoTaskStatus;
use Bitrix\Im\V2\Result;
use Bitrix\Im\V2\Service\Locator;
use Bitrix\Main\Application;
use Bitrix\Main\DI\ServiceLocator;

class MultipleMessagesCreator extends AbstractCreator
{
	public const CONTEXT_ID = 'chat_task_creation_from_multiple_messages';
	private const LOCK_TIMEOUT = 3;
	private const AUTO_TASK_TYPE = AutoTaskType::TaskCreationMulti;

	private MessageCollection $messages;

	public function __construct(MessageCollection $messages)
	{
		// collection entries are keyed by message id and the combined text must follow
		// the chat order, so sort once here; params, files and completed transcriptions
		// are pre-loaded in batch to avoid per-message queries during the scans below
		$messages->ksort();
		$messages->fillParams();
		$messages->getFiles()->fillTranscriptions();

		$this->messages = $messages;
		$this->message = self::resolveAnchorMessage($this->messages);
	}

	public function sendAiQuery(): Result
	{
		$result = new Result();

		if (!$this->canSendQuery())
		{
			return $result;
		}

		$messageIds = $this->messages->getIds();
		$userId = $this->getContext()->getUserId();
		$queueManager = AutoTaskQueueManager::getInstance();

		// cheap dedup pre-check, so duplicate clicks skip the selection scan below
		if ($queueManager->inQueue($messageIds, self::AUTO_TASK_TYPE))
		{
			return $result;
		}

		$pendingTargets = $this->getPendingTranscriptionTargets();

		if (!$this->registerBatch($queueManager, $messageIds, array_keys($pendingTargets)))
		{
			return $result;
		}

		if (empty($pendingTargets))
		{
			return $this->sendCombinedQuery($userId);
		}

		// the search status must precede launchTranscriptions: a fully short-circuited
		// batch advances to the combined query (or a terminal status) synchronously,
		// and a later Search would reopen the magic panel after its terminal status
		(new AutoTaskStatus($this->message, $userId, Status::Search))->send();

		$this->launchTranscriptions($pendingTargets, $messageIds, $userId);

		return $result;
	}

	public function createTask(array $data, string $sourceText, int $userId): void
	{
		if ($this->isEmptyMessage())
		{
			return;
		}

		Locator::getContext()->setUser($userId);

		/** @var MultipleMessagesTaskHandler $handler */
		$handler = ServiceLocator::getInstance()->get(MultipleMessagesTaskHandler::class);
		$handler->handle($data, $this->messages, $this->message, $sourceText, $userId);
	}

	public function buildCombinedSourceText(): string
	{
		$parts = [];
		$usedTranscriptions = [];

		foreach ($this->messages as $message)
		{
			if ($this->isContentlessMessage($message))
			{
				continue;
			}

			$text = self::extractSourceTextFrom($message);
			if ($text !== '')
			{
				$parts[] = $text;
			}

			$fileItem = $this->getTranscribableFile($message);
			if ($fileItem === null || isset($usedTranscriptions[$fileItem->getOriginalFileId()]))
			{
				continue;
			}

			$usedTranscriptions[$fileItem->getOriginalFileId()] = true;

			$transcriptText = trim((string)($fileItem->getCompletedTranscription()?->transcriptText ?? ''));
			if ($transcriptText !== '')
			{
				$parts[] = $transcriptText;
			}
		}

		return trim(implode("\n", $parts));
	}

	/**
	 * Reacts to one finished (successfully or not) batch transcription: removes the file
	 * from the pending set and, once the batch has no pending transcriptions left, fires
	 * the combined AI query for the whole selection (SC-002, AC-029).
	 *
	 * A failed transcription ($failed) means the content of that message is unavailable, so
	 * the whole batch is aborted instead of building a partial/minimal task (PRD ERR-013).
	 *
	 * Static on purpose: the partial-callback path only needs the message ids and the
	 * finished file id, so the whole MessageCollection is loaded only once the batch
	 * is actually drained.
	 */
	public static function onTranscriptionFinished(array $messageIds, int $fileId, int $userId, bool $failed = false): void
	{
		$queueManager = AutoTaskQueueManager::getInstance();
		$lockName = self::buildLockName($messageIds);

		if (!Application::getConnection()->lock($lockName, self::LOCK_TIMEOUT))
		{
			// proceeding without the lock could lose a concurrent advance or fire the
			// combined query twice; an abandoned batch is covered by the record TTL
			// in AutoTaskQueueManager
			return;
		}

		$aborted = false;
		try
		{
			$row = $queueManager->getRow($messageIds, self::AUTO_TASK_TYPE);
			$params = $row['params'] ?? null;
			if ($params === null)
			{
				return;
			}

			$pendingBefore = array_map('intval', $params['pendingFileIds'] ?? []);
			if (!in_array($fileId, $pendingBefore, true))
			{
				// this file is already accounted for (duplicate or late callback);
				// falling through here would fire the combined query a second time
				return;
			}

			if ($failed)
			{
				// failed transcription = this message's content is unavailable: abort the whole
				// task creation (no partial/minimal task). Deleting the record under the lock makes
				// any later callback for the other batch files a no-op (getRow() === null).
				$queueManager->delete($messageIds, self::AUTO_TASK_TYPE);
				$aborted = true;
			}
			else
			{
				$pending = array_values(array_filter(
					$pendingBefore,
					static fn (int $id) => $id !== $fileId
				));
				$params['pendingFileIds'] = $pending;
				$queueManager->updateParamsById($row['id'], $params);

				if (!empty($pending))
				{
					return;
				}
			}
		}
		finally
		{
			Application::getConnection()->unlock($lockName);
		}

		if ($aborted)
		{
			// the record was already deleted under the lock above; only notify the initiator
			self::sendTerminalNotFound($messageIds, $userId, new MessageCollection($messageIds));

			return;
		}

		$messages = new MessageCollection($messageIds);
		if (!self::isSelectionComplete($messageIds, $messages))
		{
			// at least one selected message was deleted before the batch drained (ERR-015)
			self::abortBatchByIds($messageIds, $userId, $messages);

			return;
		}

		(new self($messages))->sendCombinedQuery($userId);
	}

	/**
	 * The selection is intact only when every originally selected message is still available.
	 * A fully deleted message drops out of the reloaded MessageCollection, so a shorter
	 * collection means at least one message disappeared and the whole batch must be aborted
	 * (PRD ERR-015). Soft-delete is intentionally NOT treated as a break (decision 2026-06-25).
	 */
	public static function isSelectionComplete(array $expectedIds, MessageCollection $messages): bool
	{
		return $messages->count() === count(array_unique(array_map('intval', $expectedIds)));
	}

	/**
	 * Aborts the batch from a static context (transcription barrier / queue job): deletes the
	 * dedup record and sends the initiator a terminal status, so the magic panel closes. The
	 * status anchor is resolved from the surviving collection, falling back to the formal anchor
	 * id when the whole selection is gone.
	 */
	public static function abortBatchByIds(array $messageIds, int $userId, ?MessageCollection $messages = null): void
	{
		AutoTaskQueueManager::getInstance()->delete($messageIds, self::AUTO_TASK_TYPE);
		self::sendTerminalNotFound($messageIds, $userId, $messages);
	}

	/**
	 * Sends the terminal NotFound status to the initiator without touching the queue record
	 * (used when the record was already deleted under the lock — re-deleting it key-based and
	 * outside the lock could wipe a batch that a concurrent identical selection just re-registered).
	 */
	private static function sendTerminalNotFound(array $messageIds, int $userId, ?MessageCollection $messages = null): void
	{
		if ($userId <= 0)
		{
			return;
		}

		$anchor = ($messages !== null && !$messages->isEmpty())
			? self::resolveAnchorMessage($messages)
			: (new Message())->setMessageId(self::resolveAnchorMessageId($messageIds));

		(new AutoTaskStatus($anchor, $userId, Status::NotFound))->send();
	}

	/**
	 * The anchor (the newest message of the selection) keys all pull statuses, the chat-task
	 * link and the notification, so every reporter must resolve it the same way.
	 */
	public static function resolveAnchorMessageId(array $messageIds): int
	{
		return empty($messageIds) ? 0 : (int)max($messageIds);
	}

	/**
	 * Registers the dedup/batch-state record for this selection under a connection lock.
	 * Returns false when a concurrent attempt for the same selection already owns it.
	 */
	private function registerBatch(AutoTaskQueueManager $queueManager, array $messageIds, array $pendingFileIds): bool
	{
		if (!Application::getConnection()->lock($this->getLockName(), self::LOCK_TIMEOUT))
		{
			// a concurrent attempt for the same selection holds the lock - let it finish
			return false;
		}

		try
		{
			if ($queueManager->inQueue($messageIds, self::AUTO_TASK_TYPE))
			{
				return false;
			}

			$queueManager->add($messageIds, self::AUTO_TASK_TYPE, [
				'pendingFileIds' => $pendingFileIds,
			]);

			return true;
		}
		finally
		{
			Application::getConnection()->unlock($this->getLockName());
		}
	}

	/**
	 * Builds the combined AI query from all selected messages (texts + ready transcriptions)
	 * and queues it for the engine. Aborts the batch on synchronous failure.
	 */
	private function sendCombinedQuery(int $userId): Result
	{
		$result = new Result();

		Locator::getContext()->setUser($userId);

		$sourceText = $this->buildCombinedSourceText();

		if ($sourceText === '')
		{
			$this->createMinimalTask($userId);
			AutoTaskQueueManager::getInstance()->delete($this->messages->getIds(), self::AUTO_TASK_TYPE);

			return $result;
		}

		if (mb_strlen($sourceText) > TranscribeManager::MAX_TRANSCRIPTION_CHARS)
		{
			$this->abortBatch($userId);

			return $result->addError(new CopilotError(CopilotError::MAX_TRANSCRIPTION_CHARS));
		}

		$context = new Context(self::MODULE_ID, self::CONTEXT_ID);
		$context->setParameters([
			'messageIds' => $this->messages->getIds(),
			'userId' => $userId,
			'sourceText' => $sourceText,
		]);

		$result = $this->dispatchTaskExtractionQuery($context, $sourceText, $userId);

		if (!$result->isSuccess())
		{
			$this->abortBatch($userId);
		}

		return $result;
	}

	/**
	 * After the barrier the batch runs in a queue callback where the returned Result has
	 * no reader, so on failure the initiator must get a terminal pull status - otherwise
	 * the magic panel waits forever.
	 */
	private function abortBatch(int $userId): void
	{
		self::abortBatchByIds($this->messages->getIds(), $userId, $this->messages);
	}

	protected function hasMinimalTaskContent(): bool
	{
		foreach ($this->messages as $message)
		{
			if ($this->messageHasMinimalTaskContent($message))
			{
				return true;
			}
		}

		return false;
	}

	protected function getMinimalTaskTitle(): string
	{
		foreach ($this->messages as $message)
		{
			$title = $this->getFileBasedTitle($message);
			if ($title !== null)
			{
				return $title;
			}
		}

		return $this->getDefaultTaskTitle();
	}

	/**
	 * @param array<int, array{message: Message, fileItem: FileItem}> $pendingTargets keyed by original file id
	 */
	private function launchTranscriptions(array $pendingTargets, array $messageIds, int $userId): void
	{
		$skipped = [];

		foreach ($pendingTargets as $originalFileId => $target)
		{
			$fileItem = $target['fileItem'];
			$message = $target['message'];

			$transcribeManager = new TranscribeManager(
				$fileItem->getOriginalFileId(),
				$fileItem->getDiskFileId(),
				(int)$message->getChatId(),
				(int)$message->getMessageId(),
			);

			$transcribeResult = $transcribeManager->transcribeFile(
				LaunchType::Manual,
				[
					'multiMessageIds' => $messageIds,
					'userId' => $userId,
				]
			);

			if (!$transcribeResult->isNewJobQueued())
			{
				// A short-circuit means the transcription is already complete or owned by another
				// flow (isSuccess), or it failed synchronously (!isSuccess). A synchronous failure
				// makes the content unavailable, so the batch must fail-fast exactly like an async
				// transcription failure (ERR-013, Phase 5) instead of building a partial task.
				$skipped[(int)$originalFileId] = !$transcribeResult->isSuccess();
			}
		}

		// No queue callback with our batch context will arrive for these files. Advance the barrier
		// right away (failed=true aborts the whole batch), otherwise it would wait until the TTL.
		// A file owned by another flow joins the combined text only when its transcript lands
		// before the batch drains - an accepted limitation (SDD Q-1).
		foreach ($skipped as $skippedFileId => $failed)
		{
			self::onTranscriptionFinished($messageIds, $skippedFileId, $userId, $failed);
		}
	}

	/**
	 * @return array<int, array{message: Message, fileItem: FileItem}> keyed by original file id
	 */
	private function getTranscribableTargets(): array
	{
		$targets = [];

		foreach ($this->messages as $message)
		{
			$fileItem = $this->getTranscribableFile($message);
			if ($fileItem === null)
			{
				continue;
			}

			$targets[$fileItem->getOriginalFileId()] = [
				'message' => $message,
				'fileItem' => $fileItem,
			];
		}

		return $targets;
	}

	/**
	 * Transcribable files that do not have a completed transcription yet.
	 *
	 * @return array<int, array{message: Message, fileItem: FileItem}> keyed by original file id
	 */
	private function getPendingTranscriptionTargets(): array
	{
		return array_filter(
			$this->getTranscribableTargets(),
			static fn (array $target) => $target['fileItem']->getCompletedTranscription() === null
		);
	}

	/**
	 * A selected message is transcribable only when it is a voice note or a video note
	 * (same rule the CreatorFactory uses to route the single-message transcription flow).
	 * Such a message always carries exactly one file — its note. The isTranscribable()
	 * check carries the size/type limits the single flow enforces before transcription;
	 * files that fail it degrade to plain attachments instead of wasting an AI request.
	 */
	private function getTranscribableFile(Message $message): ?FileItem
	{
		if (!$message->isVoiceNote() && !$message->isVideoNote())
		{
			return null;
		}

		$fileItem = $message->getFiles()->getAny();
		if ($fileItem === null || !$fileItem->isTranscribable())
		{
			return null;
		}

		return $fileItem;
	}

	protected function canSendQuery(): bool
	{
		return !$this->isEmptyMessage() && !$this->isDeniedChatForAutoTask();
	}

	private function getLockName(): string
	{
		return self::buildLockName($this->messages->getIds());
	}

	private static function buildLockName(array $messageIds): string
	{
		return 'im_auto_task_creation_multi_' . self::AUTO_TASK_TYPE->buildEntityId($messageIds);
	}

	/**
	 * Resolves the anchor for an already-loaded selection, preferring a surviving message:
	 * the newest message that still exists in the collection, or any survivor, falling back
	 * to a bare Message keyed by the formal anchor id only for an empty collection. Both the
	 * live creator and the queue job must resolve it from the real collection the same way,
	 * so terminal statuses target one and the same message id.
	 */
	public static function resolveAnchorMessage(MessageCollection $messages): Message
	{
		$anchorId = self::resolveAnchorMessageId($messages->getIds());

		return $messages[$anchorId] ?? $messages->getAny() ?? new Message($anchorId);
	}
}
