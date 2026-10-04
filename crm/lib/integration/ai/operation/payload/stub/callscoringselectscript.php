<?php

namespace Bitrix\Crm\Integration\AI\Operation\Payload\Stub;

use Bitrix\Crm\Copilot\CallAssessment\Controller\CopilotCallAssessmentController;
use Bitrix\Crm\Integration\AI\Operation\Payload\StubInterface;
use Bitrix\Main\Web\Json;

final class CallScoringSelectScript implements StubInterface
{
	public function makeStub(): string
	{
		return Json::encode([
			'scriptSelection' => [
				'scriptId' => $this->getScriptId(),
				'confidence' => random_int(30, 90),
				'rationale' => 'The call transcript perfectly matched the script criteria.',
			],
		]);
	}

	private function getScriptId(): int
	{
		$items = CopilotCallAssessmentController::getInstance()->getList([
			'select' => ['ID'],
			'filter' => [
				'IS_ENABLED' => 'Y',
			],
		])->collectValues();

		$firstItem = reset($items);

		return $firstItem ? (int)$firstItem['ID'] : 0;
	}
}
