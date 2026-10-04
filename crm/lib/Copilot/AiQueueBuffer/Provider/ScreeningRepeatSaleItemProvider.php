<?php

namespace Bitrix\Crm\Copilot\AiQueueBuffer\Provider;

use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\RepeatSale\Transcription\CallTranscriptionGate;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use CCrmOwnerType;

final class ScreeningRepeatSaleItemProvider implements QueueBufferProviderInterface
{
	private CallTranscriptionGate $transcriptionGate;

	public function __construct(?CallTranscriptionGate $transcriptionGate = null)
	{
		$this->transcriptionGate = $transcriptionGate ?? new CallTranscriptionGate();
	}

	public static function getId(): int
	{
		return 2;
	}

	public function process(?array $data = null, bool $isFinalAttempt = false): Result
	{
		$result = new Result();

		if (empty($data))
		{
			$error = new Error('Provider data must be specified', 'PROVIDER_DATA_EMPTY');

			return $result->addError($error);
		}

		$clientEntityTypeId = (int)($data['clientEntityTypeId'] ?? 0);
		$clientEntityId = (int)($data['clientEntityId'] ?? 0);

		// The collector treats baseDealId as a Deal id. Pass it only when the screened entity is a
		// Deal; otherwise the collector would query call activities of a non-Deal id as if it were a
		// deal. Same guard the tips provider applies to BASE_ENTITY_*.
		$baseDealId = $clientEntityTypeId === CCrmOwnerType::Deal ? $clientEntityId : 0;

		$transcriptionResult = $this->transcriptionGate->ensure(
			$data['clientIdentifiers'] ?? [],
			$baseDealId,
			$isFinalAttempt,
		);
		if (!$transcriptionResult->isSuccess())
		{
			// NotReady (OPERATION_IS_PENDING) => Consumer defers the item to the end of the queue.
			return $transcriptionResult;
		}

		return AIManager::launchScreeningRepeatSaleItem(
			new ItemIdentifier($clientEntityTypeId, $clientEntityId),
			$data['segmentId'] ?? 0,
			$data['clientIdentifiers'] ?? [],
		);
	}
}
