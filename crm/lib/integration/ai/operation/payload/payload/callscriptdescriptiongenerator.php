<?php

namespace Bitrix\Crm\Integration\AI\Operation\Payload\Payload;

use Bitrix\Crm\Copilot\CallAssessment\Enum\CallType;
use Bitrix\Crm\Copilot\CallAssessment\Enum\ClientType;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Operation\Payload\CalcMarkersInterface;
use Bitrix\Crm\Integration\AI\Operation\Payload\PayloadInterface;
use Bitrix\Crm\Integration\AI\Operation\Payload\RequiredInputInterface;
use Bitrix\Main\Web\Json;

final class CallScriptDescriptionGenerator extends AbstractPayload implements
	CalcMarkersInterface,
	RequiredInputInterface
{
	use Trait\CallAssessmentDataLoaderTrait;

	private const MARKER_CRITERIA = 'criteria';
	private const MARKER_CLIENT_TYPES = 'client_types';
	private const MARKER_CALL_DIRECTION = 'call_direction';

	private ?array $markerData = null;

	public function getPayloadCode(): string
	{
		return 'script_description_generator';
	}

	public function setMarkers(array $markers): PayloadInterface
	{
		$this->markers = array_merge($markers, $this->calcMarkers());

		return $this;
	}

	public function calcMarkers(): array
	{
		$data = $this->getMarkerData();

		return [
			self::MARKER_CRITERIA => Json::encode($data[self::MARKER_CRITERIA]),
			self::MARKER_CLIENT_TYPES => Json::encode($data[self::MARKER_CLIENT_TYPES]),
			self::MARKER_CALL_DIRECTION => $data[self::MARKER_CALL_DIRECTION],
		];
	}

	/**
	 * The prompt has no fallback for a script without criteria, client types or call direction.
	 */
	public function hasRequiredInput(): bool
	{
		return $this->getMissingRequiredInput() === [];
	}

	public function getMissingRequiredInput(): array
	{
		$data = $this->getMarkerData();

		$missing = [];

		if ($data[self::MARKER_CRITERIA] === [])
		{
			$missing[] = self::MARKER_CRITERIA;
		}

		if ($data[self::MARKER_CLIENT_TYPES] === [])
		{
			$missing[] = self::MARKER_CLIENT_TYPES;
		}

		if ($data[self::MARKER_CALL_DIRECTION] === null)
		{
			$missing[] = self::MARKER_CALL_DIRECTION;
		}

		return $missing;
	}

	private function getMarkerData(): array
	{
		if ($this->markerData !== null)
		{
			return $this->markerData;
		}

		$assessmentSettingsId = $this->identifier->getEntityId();
		$typeIds = $this->loadAssessmentTypeIds($assessmentSettingsId);

		$this->markerData = [
			self::MARKER_CRITERIA => $this->collectCriteria($assessmentSettingsId),
			self::MARKER_CLIENT_TYPES => array_map(
				static fn(ClientType $clientType): string => $clientType->name,
				$this->collectClientTypes($typeIds['clientTypeIds']),
			),
			self::MARKER_CALL_DIRECTION => CallType::tryFrom($typeIds['callType'])?->name,
		];

		return $this->markerData;
	}

	/**
	 * The contract accepts an element only with both strings filled.
	 *
	 * @return array<int, array{name: string, description: string}>
	 */
	private function collectCriteria(int $assessmentSettingsId): array
	{
		$criteria = [];

		foreach ($this->loadCriteria($assessmentSettingsId) as $criterion)
		{
			$name = trim((string)($criterion['name'] ?? ''));
			$description = trim((string)($criterion['description'] ?? ''));

			if ($name === '' || $description === '')
			{
				continue;
			}

			$criteria[] = [
				'name' => $name,
				'description' => $description,
			];
		}

		return $criteria;
	}

	/**
	 * @param int[] $clientTypeIds
	 *
	 * @return ClientType[]
	 */
	private function collectClientTypes(array $clientTypeIds): array
	{
		$clientTypes = [];

		foreach (array_unique($clientTypeIds) as $clientTypeId)
		{
			$clientType = ClientType::tryFrom($clientTypeId);
			if ($clientType === null)
			{
				AIManager::logger()->warning(
					'{date}: {class}: unknown client type {clientType} of call script {assessmentId} - skipping',
					[
						'class' => self::class,
						'clientType' => $clientTypeId,
						'assessmentId' => $this->identifier->getEntityId(),
					],
				);

				continue;
			}

			$clientTypes[] = $clientType;
		}

		if (in_array(ClientType::ANY, $clientTypes, true))
		{
			return [ClientType::ANY];
		}

		return $clientTypes;
	}
}
