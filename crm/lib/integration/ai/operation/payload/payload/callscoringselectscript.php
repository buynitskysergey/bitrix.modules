<?php

namespace Bitrix\Crm\Integration\AI\Operation\Payload\Payload;

use Bitrix\Crm\Copilot\CallAssessment\AssessmentClientTypeResolver;
use Bitrix\Crm\Copilot\CallAssessment\Controller\CopilotCallAssessmentController;
use Bitrix\Crm\Copilot\CallAssessment\Enum\CallType;
use Bitrix\Crm\Copilot\CallAssessment\Enum\ClientType;
use Bitrix\Crm\Integration\AI\Operation\Payload\CalcMarkersInterface;
use Bitrix\Crm\Integration\AI\Operation\Payload\PayloadInterface;
use Bitrix\Crm\Integration\VoxImplantManager;
use Bitrix\Crm\Requisite\EntityLink;
use Bitrix\Main\Web\Json;
use CCrmActivityDirection;

final class CallScoringSelectScript extends AbstractPayload implements CalcMarkersInterface
{
	public function getPayloadCode(): string
	{
		return 'dialog_script_selector'; // @todo rename
	}

	public function setMarkers(array $markers): PayloadInterface
	{
		$this->markers = array_merge($markers, $this->calcMarkers());

		return $this;
	}

	public function calcMarkers(): array
	{
		$callData = $this->getCallData();
		if (empty($callData))
		{
			return [];
		}

		$scripts = $this->getScripts();
		if (empty($scripts))
		{
			return [];
		}

		return [
			'call' => in_array('call', $this->encodedMarkers, true)
				? Json::encode($callData)
				: $callData,
			'scripts' => in_array('scripts', $this->encodedMarkers, true)
				? Json::encode($scripts)
				: $scripts,
		];
	}

	private function getCallData(): array
	{
		$activity = $this->getActivity();
		if (empty($activity))
		{
			return [];
		}

		if ($activity['PROVIDER_ID'] !== \Bitrix\Crm\Activity\Provider\Call::ACTIVITY_PROVIDER_ID)
		{
			return [];
		}

		$transcription = $this->additionalData['transcription'] ?? '';
		if (empty($transcription))
		{
			return [];
		}

		return [
			'agentName' => $this->resolveUserName((int)($activity['RESPONSIBLE_ID'] ?? 0)),
			'agentDepartment' => $this->resolveCompanyName(),
			'transcript' => $transcription,
			'direction' => $this->resolveDirection((int)($activity['DIRECTION'] ?? 0)),
			'durationSec' => $this->resolveCallDuration($activity),
		];
	}

	private function resolveUserName(int $userId): string
	{
		return $this->getUserName($userId);
	}

	private function resolveCompanyName(): string
	{
		return $this->getCompanyName(EntityLink::getDefaultMyCompanyId());
	}

	private function resolveDirection(int $direction): string
	{
		return match ($direction)
		{
			CCrmActivityDirection::Incoming => 'incoming',
			CCrmActivityDirection::Outgoing => 'outgoing',
			default => '',
		};
	}

	private function resolveCallDuration(array $activity): int
	{
		$originId = $activity['ORIGIN_ID'] ?? '';
		if (is_string($originId) && VoxImplantManager::isVoxImplantOriginId($originId))
		{
			$callId = VoxImplantManager::extractCallIdFromOriginId($originId);

			return VoxImplantManager::getCallDuration($callId) ?? 0;
		}

		return 0;
	}

	private function getScripts(): array
	{
		$baseFilter = [
			'IS_ENABLED' => 'Y',
			'!=CRITERIA.ID' => null,
		];

		$assessmentSettingsIds = $this->additionalData['assessmentSettingsIds'] ?? [];
		if (empty($assessmentSettingsIds))
		{
			$scripts = $this->fetchScripts(array_merge($baseFilter, $this->buildCallAndClientTypeFilter()));

			return empty($scripts) ? $this->fetchScripts($baseFilter) : $scripts;
		}

		$baseFilter['ID'] = $assessmentSettingsIds;

		// the caller has already decided which settings are admissible, and the default limit of the
		// controller is 10 - without an explicit one a longer list would be silently cut here
		return $this->fetchScripts($baseFilter, count($assessmentSettingsIds));
	}

	private function buildCallAndClientTypeFilter(): array
	{
		$activity = $this->getActivity();
		if (empty($activity))
		{
			return [];
		}

		$filter = [];

		$callType = match ((int)($activity['DIRECTION'] ?? 0))
		{
			CCrmActivityDirection::Incoming => CallType::INCOMING,
			CCrmActivityDirection::Outgoing => CallType::OUTGOING,
			default => null,
		};
		if ($callType !== null)
		{
			$filter['=CALL_TYPE'] = [CallType::ALL->value, $callType->value];
		}

		$clientType = (new AssessmentClientTypeResolver())->resolveByActivityId($this->identifier->getEntityId());
		if ($clientType !== null)
		{
			$filter['=CLIENT_TYPES.CLIENT_TYPE_ID'] = [$clientType->value, ClientType::ANY->value];
		}

		return $filter;
	}

	private function fetchScripts(array $filter, ?int $limit = null): array
	{
		$params = [
			'select' => ['ID', 'TITLE', 'DESCRIPTION'],
			'filter' => $filter,
		];
		if ($limit !== null)
		{
			$params['limit'] = $limit;
		}

		$items = CopilotCallAssessmentController::getInstance()->getList($params)->collectValues();

		$result = [];
		foreach ($items as $item)
		{
			$result[] = [
				'id' => $item['ID'],
				'name' => $item['TITLE'],
				'description' => $item['DESCRIPTION'],
			];
		}

		return $result;
	}
}
