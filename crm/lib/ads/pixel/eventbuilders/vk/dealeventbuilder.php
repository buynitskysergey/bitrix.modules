<?php

namespace Bitrix\Crm\Ads\Pixel\EventBuilders\Vk;

use Bitrix\Seo\Conversion\Vk\Event;

class DealEventBuilder extends AbstractVkBuilder
{
	private const EVENT_STATUS = 'WON';

	protected function getOriginId(): ?string
	{
		$leadId = (int)($this->entity['LEAD_ID'] ?? 0);
		if ($leadId > 0)
		{
			return $this->leadOriginResolver->resolve($leadId);
		}

		$dealId = (int)($this->entity['ID'] ?? 0);

		return $dealId > 0 ? $this->leadOriginResolver->resolveDeal($dealId) : null;
	}

	protected function getEventName(): string
	{
		return Event::EVENT_SALE;
	}

	protected function getEventStatus(): string
	{
		return self::EVENT_STATUS;
	}

	protected function getAmount(): ?string
	{
		if (!array_key_exists('OPPORTUNITY', $this->entity) || $this->entity['OPPORTUNITY'] === null)
		{
			return null;
		}

		return (string)$this->entity['OPPORTUNITY'];
	}

	protected function getCurrency(): ?string
	{
		if (!array_key_exists('CURRENCY_ID', $this->entity) || $this->entity['CURRENCY_ID'] === null)
		{
			return null;
		}

		return (string)$this->entity['CURRENCY_ID'];
	}

}
