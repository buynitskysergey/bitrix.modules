<?php

namespace Bitrix\Crm\Copilot\AiQueueBuffer\Provider;

use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\RepeatSale\Transcription\CallTranscriptionGate;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use CCrmOwnerType;

final class FillRepeatSaleTipsProvider implements QueueBufferProviderInterface
{
	private CallTranscriptionGate $transcriptionGate;

	public function __construct(?CallTranscriptionGate $transcriptionGate = null)
	{
		$this->transcriptionGate = $transcriptionGate ?? new CallTranscriptionGate();
	}

	public static function getId(): int
	{
		return 1;
	}

	public function process(?array $data = null, bool $isFinalAttempt = false): Result
	{
		$result = new Result();

		if (empty($data))
		{
			return $result->addError(
				new Error(
					'Provider data must be specified',
					'PROVIDER_DATA_EMPTY'
				)
			);
		}

		$activityId = (int)($data['activityId'] ?? 0);
		if ($activityId <= 0)
		{
			return $result->addError(
				new Error(
					'Activity ID  must be specified',
					'ACTIVITY_ID_EMPTY'
				)
			);
		}

		$activity = Container::getInstance()->getActivityBroker()->getById($activityId);
		$userId = $activity['RESPONSIBLE_ID'] ?? null;

		$providerParams = is_array($activity) ? ($activity['PROVIDER_PARAMS'] ?? []) : [];
		$transcriptionResult = $this->transcriptionGate->ensure(
			$this->resolveClientIdentifiers($providerParams),
			$this->resolveBaseDealId($providerParams),
			$isFinalAttempt,
		);
		if (!$transcriptionResult->isSuccess())
		{
			// NotReady (OPERATION_IS_PENDING) => Consumer defers the item to the end of the queue.
			return $transcriptionResult;
		}

		return AIManager::launchFillRepeatSaleTips($activityId, $userId);
	}

	/**
	 * Client (Company/Contact) identifiers read from the same PROVIDER_PARAMS source as RepeatSalesPrompt.
	 *
	 * @param array<string, mixed> $providerParams
	 *
	 * @return array<int, array{entityTypeId: int, entityId: int}>
	 */
	private function resolveClientIdentifiers(array $providerParams): array
	{
		// Same source as RepeatSalesPrompt::calcMarkers(): prefer CLIENT_ENTITY_*, fall back to
		// BASE_ENTITY_*.
		$clientEntityTypeId = (int)($providerParams['CLIENT_ENTITY_TYPE_ID'] ?? $providerParams['BASE_ENTITY_TYPE_ID'] ?? 0);
		$clientEntityId = (int)($providerParams['CLIENT_ENTITY_ID'] ?? $providerParams['BASE_ENTITY_ID'] ?? 0);
		if ($clientEntityId <= 0)
		{
			return [];
		}

		// The collector selects the client's other deals by Company/Contact bindings only. When the
		// fallback resolves to a Deal (BASE_ENTITY_* is the base deal), the collector would find no
		// binding and silently return an empty deals_list. The base deal is passed separately via
		// resolveBaseDealId(), so keep only Company/Contact here.
		if ($clientEntityTypeId !== CCrmOwnerType::Contact && $clientEntityTypeId !== CCrmOwnerType::Company)
		{
			return [];
		}

		return [
			['entityTypeId' => $clientEntityTypeId, 'entityId' => $clientEntityId],
		];
	}

	/**
	 * @param array<string, mixed> $providerParams
	 */
	private function resolveBaseDealId(array $providerParams): int
	{
		$baseEntityTypeId = (int)($providerParams['BASE_ENTITY_TYPE_ID'] ?? 0);
		$baseEntityId = (int)($providerParams['BASE_ENTITY_ID'] ?? 0);

		return $baseEntityTypeId === CCrmOwnerType::Deal && $baseEntityId > 0 ? $baseEntityId : 0;
	}
}
