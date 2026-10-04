<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\ProductRow;

use Bitrix\Crm\Model\Dynamic\TypeTable;
use Bitrix\Crm\V2\Public\OwnerType;

/**
 * Which smart-process-based entity types work with product rows on this portal right now.
 *
 * The answer is a row of the type table and changes while the portal runs, so it is asked here and not
 * written down anywhere: SmartInvoice and SmartDocument look like built-in entities, yet their flag is
 * stored and switched exactly the way a custom smart process' one is. Lead, deal and quote are not asked
 * about at all - their answer is a constant of the product.
 *
 * SmartB2eDocument is left out whatever its row says: {@see \Bitrix\Crm\V2\Public\EntityTypeSettings}
 * hardcodes products off for it, and a type CRM treats as product-less must not look otherwise here.
 *
 * Not final on purpose: the reader that degrades when this read fails
 * ({@see \Bitrix\Crm\V2\Infrastructure\Rest\CustomController\SchemaProvider}) is exercised by putting a
 * failing instance of this class in the service locator.
 *
 * @internal
 */
class ProductEnabledTypeRepository
{
	/**
	 * @return int[]
	 */
	public function getEntityTypeIds(): array
	{
		$rows = TypeTable::query()
			->setSelect(['ENTITY_TYPE_ID'])
			->where('IS_LINK_WITH_PRODUCTS_ENABLED', true)
			->whereNot('ENTITY_TYPE_ID', OwnerType::SMART_B2E_DOCUMENT)
			->fetchAll()
		;

		$entityTypeIds = [];
		foreach ($rows as $row)
		{
			$entityTypeIds[] = (int)$row['ENTITY_TYPE_ID'];
		}

		return $entityTypeIds;
	}
}
