<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Integration\Bizproc;

use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Note\Internal\Integration\Bizproc\Document\NoteDocument;

class WorkflowLauncher
{
	public function isEngineAvailable(): bool
	{
		if (!$this->includeProcessEngine())
		{
			return false;
		}

		return class_exists(\Bitrix\Bizproc\Starter\Starter::class);
	}

	public function launch(
		string $triggerCode,
		int $documentId,
		array $eventData = [],
		?int $initiatorId = null,
	): Result
	{
		if (!$this->isEngineAvailable())
		{
			return (new Result())->addError(new Error('The process engine is not available.'));
		}

		$starter = \Bitrix\Bizproc\Starter\Starter::getByScenario(\Bitrix\Bizproc\Starter\Enum\Scenario::onEvent)
			->setContext(new \Bitrix\Bizproc\Starter\Dto\ContextDto(NoteDocument::MODULE_ID))
			->setDelay(0)
			->addEvent(
				code: $triggerCode,
				documents: [
					new \Bitrix\Bizproc\Starter\Dto\DocumentDto(
						NoteDocument::complexDocumentId($documentId),
						NoteDocument::complexDocumentType(),
					),
				],
				parameters: $eventData,
				eventType: \CBPDocumentEventType::Trigger,
				userId: (int)$initiatorId,
			)
		;

		return $starter->start();
	}

	protected function includeProcessEngine(): bool
	{
		return Loader::includeModule('bizproc');
	}
}
