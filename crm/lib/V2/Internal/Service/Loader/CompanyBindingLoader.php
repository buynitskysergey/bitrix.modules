<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Loader;

use Bitrix\Crm\Binding\ContactCompanyTable;
use Bitrix\Crm\V2\Public\Entity\Item\CompanyBinding;
use Bitrix\Crm\V2\Public\Entity\Item\CompanyBindingCollection;
use Bitrix\Crm\V2\Public\Entity\Item\Item;
use Bitrix\Crm\V2\Public\OwnerType;
use Bitrix\Crm\V2\Public\Provider\Item\Param\ItemSelect;

/**
 * Loads `companyBindings` onto V2 Items. In the legacy data model only Contact has a
 * many-to-many relation with Company (via `b_crm_contact_company`); other entities use
 * a scalar `companyId` instead, which the regular ItemFieldMapper handles. Items of any
 * other type get an empty collection — invoking the loader for them is a no-op safety net,
 * not a usage hint.
 *
 * @internal
 */
final class CompanyBindingLoader
{
	/**
	 * @param Item[] $items
	 */
	public function load(array $items, ItemSelect $select): void
	{
		if (!$select->shouldLoadCompanyBindings())
		{
			return;
		}

		$persisted = array_values(array_filter($items, static fn(Item $i) => $i->getId() !== null));
		if (empty($persisted))
		{
			return;
		}

		$entityTypeId = $persisted[0]->getEntityType()->getId();
		if ($entityTypeId !== OwnerType::CONTACT)
		{
			// No bulk-binding source for non-Contact types; install empty collections so
			// callers don't see lingering nulls when they explicitly opted in.
			foreach ($persisted as $item)
			{
				$item->internalSet(Item::companyBindings, new CompanyBindingCollection());
			}

			return;
		}

		$ownerIds = array_map(static fn(Item $i) => (int)$i->getId(), $persisted);
		$bindingsByOwner = ContactCompanyTable::getBulkContactBindings($ownerIds);

		foreach ($persisted as $item)
		{
			$collection = new CompanyBindingCollection();
			foreach ($bindingsByOwner[(int)$item->getId()] ?? [] as $row)
			{
				$collection->add(new CompanyBinding(
					companyId: (int)$row['COMPANY_ID'],
					sort: (int)($row['SORT'] ?? 0),
					isPrimary: ((string)($row['IS_PRIMARY'] ?? 'N')) === 'Y',
				));
			}

			$item->internalSet(Item::companyBindings, $collection);
		}
	}
}
