<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\AI\Operation\Payload\Payload;

use Bitrix\Crm\Integration\AI\Operation\Payload\CalcMarkersInterface;
use Bitrix\Crm\Integration\AI\Operation\Payload\PayloadInterface;
use Bitrix\Main\Web\Json;

final class CallGrouping extends AbstractPayload implements CalcMarkersInterface
{
	public function getPayloadCode(): string
	{
		return 'dialog_call_group';
	}

	public function setMarkers(array $markers): PayloadInterface
	{
		$this->markers = array_merge($markers, $this->calcMarkers());

		return $this;
	}

	public function calcMarkers(): array
	{
		return [
			'calls_array' => Json::encode($this->additionalData['calls'] ?? []),
			'scripts_array' => Json::encode($this->additionalData['scripts'] ?? []),
		];
	}
}
