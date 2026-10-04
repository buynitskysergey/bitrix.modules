<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Provider\Item;

use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\OwnerType;

/**
 * Returns the typed provider for an arbitrary {@see EntityType}.
 *
 * Use this when the entity type is known only at runtime — REST controllers,
 * generic timeline/relations renderers, etc. For statically-known types prefer
 * the typed provider directly: `(new DealProvider())->getById($id, ItemSelect::all())`.
 */
final class ItemProvider
{
	public static function forEntityType(EntityType $entityType): AbstractItemProvider
	{
		return match ($entityType->getId())
		{
			OwnerType::DEAL => new DealProvider(),
			OwnerType::LEAD => new LeadProvider(),
			OwnerType::CONTACT => new ContactProvider(),
			OwnerType::COMPANY => new CompanyProvider(),
			OwnerType::QUOTE => new QuoteProvider(),
			OwnerType::SMART_INVOICE => new SmartInvoiceProvider(),
			OwnerType::SMART_DOCUMENT => new SmartDocumentProvider(),
			OwnerType::SMART_B2E_DOCUMENT => new SmartB2eDocumentProvider(),
			// EntityType::isValid guarantees only smart-process IDs reach default.
			default => new SmartProcessProvider($entityType),
		};
	}
}
