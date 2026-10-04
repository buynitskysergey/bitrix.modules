<?php

namespace Bitrix\Crm\Integration\AI\Operation\Payload\Stub;

use Bitrix\Crm\Integration\AI\Operation\Payload\StubInterface;
use Bitrix\Main\Web\Json;

final class CallCriteriaGenerator implements StubInterface
{
	public function makeStub(): string
	{
		return Json::encode([
			'name' => 'test name',
			'description' => 'test description',
			'new_criteria' => [
				[
					'name' => 'Upselling attempt',
					'description' => 'Agent should suggest additional products or services during the conversation.',
				],
				[
					'name' => 'Closing confirmation',
					'description' => 'Agent should confirm the next steps and summarize the agreement.',
				],
			],
		]);
	}
}
