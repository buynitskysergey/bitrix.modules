<?php

namespace Bitrix\Crm\Integration\AI\Operation\Payload\Payload;

use Bitrix\Crm\Integration\AI\Operation\Payload\CalcMarkersInterface;
use Bitrix\Crm\Integration\AI\Operation\Payload\PayloadInterface;
use Bitrix\Main\Web\Json;

final class CallScoringV2 extends AbstractPayload implements CalcMarkersInterface
{
	use Trait\CallAssessmentDataLoaderTrait;

	public function getPayloadCode(): string
	{
		return 'dialog_quality_scorer';
	}

	public function setMarkers(array $markers): PayloadInterface
	{
		$this->markers = array_merge($markers, $this->calcMarkers());

		return $this;
	}

	public function calcMarkers(): array
	{
		$assessmentSettingsId = $this->additionalData['assessmentSettingsId'] ?? 0;

		$script = $this->loadScript($assessmentSettingsId, ['IS_ENABLED' => 'Y']);
		if (empty($script))
		{
			return [];
		}

		$dialogue = $this->additionalData['transcription'] ?? '';
		if (empty($dialogue))
		{
			return [];
		}

		$criteria = $this->loadCriteria($assessmentSettingsId);
		if (empty($criteria))
		{
			return [];
		}

		return [
			'script_name' => $script['name'],
			'script_description' => $script['description'],
			'criteria' => Json::encode($criteria),
			'dialogue' => $dialogue,
		];
	}
}
