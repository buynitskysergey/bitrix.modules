<?php

namespace Bitrix\Crm\Ads\Pixel\EventBuilders\Vk;

use Bitrix\Seo\Conversion\Vk\Event;

final class LeadEventBuilder extends AbstractVkBuilder
{
	private const EVENT_STATUS = 'QUALIFIED';

	protected function getOriginId(): ?string
	{
		$leadId = (int)($this->entity['ID'] ?? 0);
		if ($leadId <= 0)
		{
			return null;
		}

		return $this->leadOriginResolver->resolve($leadId);
	}

	protected function getEventName(): string
	{
		return Event::EVENT_LEAD;
	}

	protected function getEventStatus(): string
	{
		return self::EVENT_STATUS;
	}
}
