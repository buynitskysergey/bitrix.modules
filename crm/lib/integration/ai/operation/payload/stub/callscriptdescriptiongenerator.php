<?php

namespace Bitrix\Crm\Integration\AI\Operation\Payload\Stub;

use Bitrix\Crm\Integration\AI\Operation\Payload\StubInterface;
use Bitrix\Main\Web\Json;

final class CallScriptDescriptionGenerator implements StubInterface
{
	public function makeStub(): string
	{
		return Json::encode([
			'name' => 'First contact',
			'description' => 'Incoming calls of new clients: the agent clarifies the task, presents the company'
				. ' and agrees on the next step.',
		]);
	}
}
