<?php

namespace Bitrix\Crm\Integration\AI\Operation\Payload\Payload;

use Bitrix\Crm\Copilot\CallAssessment\Controller\CopilotCallAssessmentController;
use Bitrix\Crm\Copilot\CallAssessment\Enum\CallType;
use Bitrix\Crm\Copilot\CallAssessment\Enum\ClientType;
use Bitrix\Crm\Integration\AI\Operation\Payload\CalcMarkersInterface;
use Bitrix\Crm\Integration\AI\Operation\Payload\PayloadInterface;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\Json;

final class CallScriptCreator extends AbstractPayload implements CalcMarkersInterface
{
	use Trait\CallAssessmentDataLoaderTrait;

	public function getPayloadCode(): string
	{
		return 'dialog_script_creator';
	}

	public function setMarkers(array $markers): PayloadInterface
	{
		$this->markers = array_merge($markers, $this->calcMarkers());

		return $this;
	}

	public function calcMarkers(): array
	{
		$userText = (string)($this->additionalData['userText'] ?? '');
		$result = [
			'user_text' => $userText,
			'existing_criteria' => Json::encode([]),
			'client_types' => '',
			'call_type' => '',
		];

		$assessmentSettingsId = (int)($this->additionalData['assessmentSettingsId'] ?? 0);
		if ($assessmentSettingsId > 0)
		{
			$entity = CopilotCallAssessmentController::getInstance()->getById($assessmentSettingsId);
			if ($entity)
			{
				$title = $entity->getTitle();
				$description = $entity->getDescription();
				if (!empty($title))
				{
					$result['script_name'] = $title;
				}
				if (!empty($description))
				{
					$result['script_description'] = $description;
				}

				$clientTypeIds = [];
				foreach ($entity->getClientTypes() as $clientType)
				{
					$clientTypeIds[] = $clientType->getClientTypeId();
				}

				$result['client_types'] = empty($clientTypeIds)
					? Loc::getMessage('CRM_AI_CALL_SCRIPT_CREATOR_ALL_CLIENTS')
					: ClientType::implodeTitles($clientTypeIds)
				;

				$callTypeId = (int)$entity->getCallType();
				$result['call_type'] = CallType::getTitle($callTypeId) ?? '';
			}

			$result['existing_criteria'] = Json::encode($this->loadCriteria($assessmentSettingsId));
		}

		return $result;
	}
}