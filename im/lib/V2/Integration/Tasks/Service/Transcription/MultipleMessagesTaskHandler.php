<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Tasks\Service\Transcription;

use Bitrix\Im\V2\Integration\AI\TaskCreation\Status;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Im\V2\Pull\Event\AutoTaskStatus;

/**
 * Creates one task from several selected messages (AI multiselect, SC-002): validates the
 * AI verdict, reports statuses by the anchor message and delegates the task pipeline to
 * TranscribedTaskHandler, forwarding the whole selection to the task chat.
 *
 * The multiselect flow intentionally supports tasks only: an EntityType::Result verdict
 * from the shared prompt degrades to NotFound here, unlike the single-message flow
 * (TranscriptionHandler), where it creates a "result" entity.
 */
class MultipleMessagesTaskHandler
{
	public function __construct(
		private readonly TranscribedTaskHandler $taskHandler,
	)
	{
	}

	public function handle(
		array $data,
		MessageCollection $messages,
		Message $anchor,
		string $combinedText,
		int $initiatorId,
	): bool
	{
		$type = EntityType::tryFrom($data['type'] ?? '');
		$taskData = $data['task_data'] ?? [];

		if ($type !== EntityType::Task || !is_array($taskData) || empty($taskData))
		{
			(new AutoTaskStatus($anchor, $initiatorId, Status::NotFound))->send();

			return false;
		}

		(new AutoTaskStatus($anchor, $initiatorId, Status::TaskCreationStarted, true))->send();

		$created = $this->taskHandler->handle($taskData, $anchor, $combinedText, $initiatorId, $messages);
		if (!$created)
		{
			// the delegate can fail without a terminal status (tasks module off, mapper null
			// or a SystemException in add); without this the initiator panel hangs forever.
			// On success TaskCreationCompleted is sent from ChatTaskLinkService, so there is
			// no double terminal here.
			(new AutoTaskStatus($anchor, $initiatorId, Status::NotFound))->send();
		}

		return $created;
	}
}
