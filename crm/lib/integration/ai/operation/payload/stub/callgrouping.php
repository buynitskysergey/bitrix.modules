<?php

namespace Bitrix\Crm\Integration\AI\Operation\Payload\Stub;

use Bitrix\Crm\Integration\AI\Operation\Payload\StubInterface;
use Bitrix\Main\Web\Json;

final class CallGrouping implements StubInterface
{
	public function makeStub(): string
	{
		// @todo
		return Json::encode([
			'groups' => [
				[
					'script_id' => null,
					'group_name' => 'Tariff info group name',
					'items' => [1001, 1002, 1003],
				],
				[
					'script_id' => 17,
					'group_name' => null,
					'items' => [1004],
				],
			],
		]);
	}
}
