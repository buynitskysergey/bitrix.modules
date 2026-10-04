<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\Item;

use Bitrix\Crm\QuoteTable;

/**
 * @internal
 */
final class QuoteRepository extends AbstractItemRepository
{
	protected function getTableClass(): string
	{
		return QuoteTable::class;
	}
}
