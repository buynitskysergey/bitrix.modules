<?php

namespace Bitrix\Crm\Integration\AI\Operation\Payload\Stub;

use Bitrix\Crm\Integration\AI\Operation\Payload\StubInterface;
use Bitrix\Main\Web\Json;

final class CallScriptCreator implements StubInterface
{
	public function makeStub(): string
	{
		return Json::encode([
			'name' => 'Cake order',
			'description' => 'A conversation with a client who is planning a celebration and choosing a suitable cake for the date.',
			'new_criteria' => [
				[
					'name' => 'Greet the client and introduce the bakery',
					'description' => 'Do this right away so the client understands who they have reached from the first seconds.',
				],
				[
					'name' => 'Ask how many people the cake is for and what weight is needed',
					'description' => 'The occasion and order parameters should be covered during the call.',
				],
			],
			'removed_criteria' => [],
			'updated_criteria' => [],
		]);
	}
}
