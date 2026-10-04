<?php

namespace Bitrix\Crm\Ads\Pixel\EventBuilders\Vk;

interface LeadOriginResolverInterface
{
	public function resolve(int $leadId): ?string;

	public function resolveDeal(int $dealId): ?string;
}
