<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Provider\Address;

use Bitrix\Crm\V2\Internal\Service\Address\LeadAddressService;
use Bitrix\Crm\V2\Public\Entity\Address\LeadAddress;

final class LeadAddressProvider
{
	private ?LeadAddressService $leadAddressService = null;

	public function get(int $leadId, int $userId): LeadAddress
	{
		$this->getLeadAddressService()->assertCanRead($leadId, $userId);

		return $this->getLeadAddressService()->readPrimary($leadId);
	}

	private function getLeadAddressService(): LeadAddressService
	{
		return $this->leadAddressService ??= new LeadAddressService();
	}
}
