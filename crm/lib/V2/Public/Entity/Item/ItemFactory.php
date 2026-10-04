<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Item;

use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\OwnerType;

class ItemFactory
{
	public static function create(EntityType $entityType): Item
	{
		return match ($entityType->getId()) {
			OwnerType::LEAD => new Lead(),
			OwnerType::DEAL => new Deal(),
			OwnerType::CONTACT => new Contact(),
			OwnerType::COMPANY => new Company(),
			OwnerType::QUOTE => new Quote(),
			OwnerType::SMART_INVOICE => new SmartInvoice(),
			OwnerType::SMART_DOCUMENT => new SmartDocument(),
			OwnerType::SMART_B2E_DOCUMENT => new SmartB2eDocument(),
			default => $entityType->isSmartProcess()
				? new SmartProcess($entityType)
				: throw new \Bitrix\Main\ArgumentException(
					"Unsupported entity type: {$entityType->getId()}"
				),
		};
	}
}
