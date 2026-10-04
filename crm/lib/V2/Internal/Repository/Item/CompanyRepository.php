<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\Item;

use Bitrix\Crm\CompanyTable;

/**
 * @internal
 */
final class CompanyRepository extends AbstractItemRepository
{
	protected function getTableClass(): string
	{
		return CompanyTable::class;
	}
}
