<?php

namespace Bitrix\Crm\Integration\AI\Operation\Payload\Payload;

use Bitrix\Crm\Integration\AI\Operation\Payload\CalcMarkersInterface;
use Bitrix\Crm\Integration\AI\Operation\Payload\PayloadInterface;
use Bitrix\Main\Web\Json;

final class CallCriteriaGenerator extends AbstractPayload implements CalcMarkersInterface
{
	use Trait\CallAssessmentDataLoaderTrait;

	public function getPayloadCode(): string
	{
		return 'dialog_quality_criteria_generator';
	}

	public function setMarkers(array $markers): PayloadInterface
	{
		$this->markers = array_merge($markers, $this->calcMarkers());

		return $this;
	}

	public function calcMarkers(): array
	{
		$transcripts = array_values(array_map(
			static fn ($value) => (string)$value,
			(array)($this->additionalData['dialogues'] ?? []),
		));
		$isCreateMode = (bool)($this->additionalData['isCreateMode'] ?? false);

		$result = ['dialogues' => Json::encode($transcripts)];

		if ($isCreateMode)
		{
			$result['existing_criteria'] = Json::encode([]);

			return $result;
		}

		$assessmentSettingsId = $this->identifier->getEntityId();
		if ($assessmentSettingsId > 0)
		{
			$script = $this->loadScript($assessmentSettingsId);
			if (!empty($script['name']))
			{
				$result['script_name'] = $script['name'];
			}
			if (!empty($script['description']))
			{
				$result['script_description'] = $script['description'];
			}
			$result['existing_criteria'] = Json::encode($this->loadCriteria($assessmentSettingsId));
		}
		else
		{
			$result['existing_criteria'] = Json::encode([]);
		}

		return $result;
	}
}
