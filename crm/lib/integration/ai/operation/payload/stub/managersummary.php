<?php

namespace Bitrix\Crm\Integration\AI\Operation\Payload\Stub;

use Bitrix\Crm\Integration\AI\Operation\Payload\StubInterface;
use Bitrix\Main\Web\Json;

final class ManagerSummary implements StubInterface
{
	public function makeStub(): string
	{
		return Json::encode([
			'per_script' => [
				[
					'script_id' => 'sales_intro',
					'strengths' => [
						['criterion_id' => 'greeting', 'comment' => 'Greets the client by name and states the purpose of the call.'],
						['criterion_id' => 'needs_discovery', 'comment' => 'Asks open questions to uncover the client needs.'],
					],
					'growth_areas' => [
						['criterion_id' => 'objection_handling', 'comment' => 'Skips price objections instead of addressing them.'],
					],
				],
				[
					'script_id' => 'upsell',
					'strengths' => [
						['criterion_id' => 'product_knowledge', 'comment' => 'Explains add-on benefits clearly.'],
					],
					'growth_areas' => [
						['criterion_id' => 'closing', 'comment' => 'Rarely proposes a concrete next step.'],
					],
				],
			],
			'cross_script_patterns' => [
				[
					'script_ids' => ['sales_intro', 'upsell'],
					'criterion_ids' => ['closing'],
					'comment' => 'Weak closing appears across both scripts.',
				],
			],
			'recommendations' => [
				[
					'priority' => 1,
					'script_ids' => ['sales_intro', 'upsell'],
					'criterion_ids' => ['closing'],
					'text' => 'Always end the call with an agreed next step and a date.',
					'example' => 'Shall we schedule a follow-up call for Thursday at 15:00?',
				],
				[
					'priority' => 2,
					'script_ids' => ['sales_intro'],
					'criterion_ids' => ['objection_handling'],
					'text' => 'Acknowledge the price objection and reframe it around value.',
					'example' => 'I understand the budget matters; let me show how this pays back in two months.',
				],
			],
			'summary' => 'Strong on discovery and product knowledge; the main priority for the period is consistent closing with a concrete next step.',
		]);
	}
}
