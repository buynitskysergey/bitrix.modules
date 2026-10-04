<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\AI\Operation\Payload\Payload;

use Bitrix\Crm\Integration\AI\Operation\Payload\CalcMarkersInterface;
use Bitrix\Crm\Integration\AI\Operation\Payload\PayloadInterface;
use Bitrix\Main\Web\Json;

final class ManagerSummary extends AbstractPayload implements CalcMarkersInterface
{
	public function getPayloadCode(): string
	{
		return 'generate_manager_summary';
	}

	public function setMarkers(array $markers): PayloadInterface
	{
		$this->markers = array_merge($markers, $this->calcMarkers());

		return $this;
	}

	public function calcMarkers(): array
	{
		return [
			'agent_name' => (string)($this->additionalData['agent_name'] ?? ''),
			'period' => (string)($this->additionalData['period'] ?? ''),
			'total_calls' => (string)(int)($this->additionalData['total_calls'] ?? 0),
			'scripts_stats' => Json::encode($this->additionalData['scripts_stats'] ?? []),
			'call_summaries' => Json::encode($this->additionalData['call_summaries'] ?? []),
		];
	}
}
