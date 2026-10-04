<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\Sale;

use Bitrix\Crm\Order\Order;
use Bitrix\Crm\Order\OrderDealSynchronizer\Products\BasketXmlId;
use Bitrix\Crm\Order\ProductManager;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Main\Loader;

/**
 * How much of each product row of a CRM Item is still to be paid.
 *
 * The answer is composed by {@see ProductManager}, a class of CRM itself, but the whole path behind it
 * is the sale module: {@see Order} extends `Bitrix\Sale\Order` and its file is not even defined
 * without that module, the basket, the payments and the payable item collections it walks are sale
 * entities. That is where the module boundary really runs, so it is isolated here - and nothing of
 * sale crosses back: the answer is a plain map of CRM product row ids to quantities.
 *
 * The order the quantities are counted against is passed in, not looked up: the binding of an Item to
 * its orders lives in a CRM table and needs no sale at all.
 *
 * @internal
 */
class OrderPayableItems
{
	/**
	 * Payable quantity per product row of the owner.
	 *
	 * With no order given the owner has nothing paid yet and its whole composition comes back. Rows of
	 * an entity type whose composition the order side cannot read - the lead and the quote, where only
	 * the deal and the smart processes are implemented - are absent from the answer, and so are the
	 * basket items that carry no product row of CRM behind them.
	 *
	 * @param int|null $orderId the order bound to the owner, if there is one.
	 * @return array<int, float>|null product row id => quantity; `null` when the sale module is
	 *         unavailable, which is not the same answer as "nothing is payable".
	 */
	public function getQuantityByRowId(EntityType $ownerType, int $ownerId, ?int $orderId = null): ?array
	{
		if (!$this->isOrderAvailable())
		{
			return null;
		}

		$manager = new ProductManager($ownerType->getId(), $ownerId);

		// a binding pointing at an order that is gone leaves the manager without one, and the whole
		// composition stays payable - the legacy path passes the missing order on and fails on it
		$order = ($orderId !== null && $orderId > 0) ? Order::load($orderId) : null;
		if ($order !== null)
		{
			$manager->setOrder($order);
		}

		$quantities = [];
		foreach ($manager->getPayableItems() as $payableItem)
		{
			// the external code is absent for a basket item that never came from a product row
			$rowId = BasketXmlId::getRowIdFromXmlId((string)($payableItem['XML_ID'] ?? ''));
			if ($rowId !== null && $rowId > 0)
			{
				$quantities[$rowId] = (float)($payableItem['QUANTITY'] ?? 0);
			}
		}

		return $quantities;
	}

	protected function isOrderAvailable(): bool
	{
		return Loader::includeModule('sale') && class_exists(Order::class);
	}
}
