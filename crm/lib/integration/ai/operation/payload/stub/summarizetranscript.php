<?php

namespace Bitrix\Crm\Integration\AI\Operation\Payload\Stub;

use Bitrix\Crm\Feature;
use Bitrix\Crm\Feature\CallScoringV2;
use Bitrix\Crm\Integration\AI\Operation\Payload\StubInterface;
use Bitrix\Main\Security\Random;
use Bitrix\Main\Web\Json;

final class SummarizeTranscript implements StubInterface
{
	public function makeStub(): string
	{
		if (!Feature::enabled(CallScoringV2::class))
		{
			return 'Stub call summary with unique text: ' . Random::getString(20);
		}

		return Json::encode([
			'summary' => 'Stub call summary with unique text: ' . Random::getString(20),
			'data' => [
				'theme' => 'stub call theme with unique text: ' . Random::getString(20),
				'product' => 'stub call product ' . Random::getString(1),
				'intent' => 'stub call intent ' . Random::getString(1),
			],
		]);
	}
}
