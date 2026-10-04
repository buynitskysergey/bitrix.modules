<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\Order;

use Bitrix\Crm\Binding\OrderEntityTable;
use Bitrix\Crm\V2\Public\EntityType;

/**
 * The single point the new layer reads the binding of a CRM Item to its orders through.
 *
 * The binding lives in a table of CRM itself, so no module of sale takes part here: which orders an
 * owner has is a question CRM answers on its own, and only the composition behind those orders belongs
 * to {@see \Bitrix\Crm\V2\Internal\Integration\Sale\OrderPayableItems}.
 *
 * Read-only: bindings are written by the order side, which this class does not touch. ORM objects never
 * leave it - the answer is plain identifiers.
 *
 * @internal
 */
class OrderBindingRepository
{
	/**
	 * The orders bound to one owner, newest first - the order the storage answers in, kept because a
	 * caller that takes the first of them takes the same one it always did.
	 *
	 * @return int[]
	 */
	public function findOrderIdsByOwner(EntityType $ownerType, int $ownerId): array
	{
		return OrderEntityTable::getOrderIdsByOwner($ownerId, $ownerType->getId());
	}
}
