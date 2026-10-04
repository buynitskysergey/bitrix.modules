<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\Item;

use Bitrix\Crm\LeadTable;

/**
 * @internal
 */
final class LeadRepository extends AbstractItemRepository
{
	protected function getTableClass(): string
	{
		return LeadTable::class;
	}
}
