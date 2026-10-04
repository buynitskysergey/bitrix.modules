<?php

namespace Bitrix\Crm\Ads\Pixel\ConversionEventTriggers\Vk;

use Bitrix\Main\Event;

class WebFormFillHandler
{
	public static function handle(Event $event): void
	{
		(new static())->process((array)$event->getParameter('result'));
	}

	protected function process(array $result): void
	{
		foreach ((array)($result['entities'] ?? []) as $entity)
		{
			if (($entity['IS_DUPLICATE'] ?? true) === true)
			{
				continue;
			}

			$entityId = (int)($entity['ENTITY_ID'] ?? 0);
			switch ($entity['ENTITY_TYPE'] ?? null)
			{
				case \CCrmOwnerType::LeadName:
					$this->triggerLead($entityId);
					break;

				case \CCrmOwnerType::DealName:
					$this->triggerDeal($entityId);
					break;
			}
		}
	}

	protected function triggerLead(int $leadId): void
	{
		LeadTrigger::onWebFormFilled($leadId);
	}

	protected function triggerDeal(int $dealId): void
	{
		DealTrigger::onWebFormFilled($dealId);
	}
}
