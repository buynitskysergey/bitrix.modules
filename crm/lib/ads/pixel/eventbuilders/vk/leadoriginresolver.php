<?php

namespace Bitrix\Crm\Ads\Pixel\EventBuilders\Vk;

use Bitrix\Crm\WebForm\Internals\ResultEntityTable;
use Bitrix\Seo\LeadAds\Service;

final class LeadOriginResolver implements LeadOriginResolverInterface
{
	public function resolve(int $leadId): ?string
	{
		return $this->resolveEntity(\CCrmOwnerType::LeadName, $leadId);
	}

	public function resolveDeal(int $dealId): ?string
	{
		return $this->resolveEntity(\CCrmOwnerType::DealName, $dealId);
	}

	private function resolveEntity(string $entityName, int $entityId): ?string
	{
		$row = ResultEntityTable::query()
			->addSelect('RESULT.ORIGIN_ID', 'ORIGIN_ID')
			->where('ENTITY_NAME', $entityName)
			->where('ITEM_ID', $entityId)
			->whereNotNull('RESULT.ORIGIN_ID')
			->whereLike('RESULT.ORIGIN_ID', Service::TYPE_VKONTAKTE . '/%')
			->setOrder(['RESULT_ID' => 'DESC'])
			->setLimit(1)
			->fetch()
		;
		$originId = $row['ORIGIN_ID'] ?? null;

		return is_string($originId) ? $originId : null;
	}
}
