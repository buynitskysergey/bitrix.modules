<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\Item;

use Bitrix\Crm\DealTable;

/**
 * @internal
 */
final class DealRepository extends AbstractItemRepository
{
	protected function getTableClass(): string
	{
		return DealTable::class;
	}
}
