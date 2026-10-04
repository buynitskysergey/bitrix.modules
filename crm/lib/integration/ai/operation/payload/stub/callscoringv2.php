<?php

namespace Bitrix\Crm\Integration\AI\Operation\Payload\Stub;

use Bitrix\Crm\Integration\AI\Operation\Payload\StubInterface;
use Bitrix\Main\Web\Json;

final class CallScoringV2 implements StubInterface
{
	public function makeStub(): string
	{
		return Json::encode([
			'criteria_scores' => [
				[
					'criterion_name' => 'Greeting',
					'met' => true,
					'comment' => 'The agent greeted the customer politely and introduced themselves.',
				],
				[
					'criterion_name' => 'Needs identification',
					'met' => false,
					'comment' => 'The agent did not ask clarifying questions.',
				],
				[
					'criterion_name' => 'Objection handling',
					'met' => null,
					'comment' => 'There were no objections during the conversation.',
				],
			],
			'recommendations' => 'It is recommended to pay more attention to identifying customer needs.',
		]);
	}
}
