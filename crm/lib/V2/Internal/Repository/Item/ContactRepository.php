<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\Item;

use Bitrix\Crm\ContactTable;

/**
 * @internal
 */
final class ContactRepository extends AbstractItemRepository
{
	protected function getTableClass(): string
	{
		return ContactTable::class;
	}
}
