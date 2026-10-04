<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\ProductRow;

use Bitrix\Crm\V2\Internal\Integration\Sale\OrderPayableItems;
use Bitrix\Crm\V2\Internal\Repository\Order\OrderBindingRepository;
use Bitrix\Crm\V2\Internal\Repository\ProductRow\ProductRowRepository;
use Bitrix\Crm\V2\Public\Entity\Item\ProductRowCollection;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\Provider\Item\ProductRow\Param\ProductRowFilter;
use Bitrix\Crm\V2\Public\Provider\Item\ProductRow\ProductRowProvider;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

/**
 * The product rows of an Item that are still to be paid, with the payable quantity in place of the
 * stored one.
 *
 * Reading, not writing: the right this scenario needs is the right to **read** the owner, and it is
 * checked by {@see ProductRowProvider} before this service is reached. Nothing here is saved - the
 * quantity is replaced on the objects that are answered with and on nothing else.
 *
 * The scenario is carried over from the legacy controller with its limits intact, and they are worth
 * knowing:
 *
 * - two orders bound to one owner refuse the read, and that refusal carries **no code** - the form
 *   clients already see, kept rather than chosen anew;
 * - rows are matched to payable items through the external code of the basket item alone
 *   (`crm_pr_<number>`), so a set of items none of which carries one is answered as an empty list,
 *   outwardly the same as "nothing is payable";
 * - for the lead and the quote the answer is always empty: the order side reads the composition of
 *   the deal and of the smart processes only, and the merge it performs walks that composition.
 *
 * @internal
 */
final class AvailableForPaymentService
{
	/** Result data key - the payable {@see ProductRowCollection} of the owner. */
	public const DATA_PRODUCT_ROWS = ProductRowProvider::DATA_PRODUCT_ROWS;

	public function __construct(
		private readonly OrderPayableItems $payableItems = new OrderPayableItems(),
		private readonly ProductRowRepository $repository = new ProductRowRepository(),
		private readonly OrderBindingRepository $orderBindings = new OrderBindingRepository(),
	)
	{
	}

	/**
	 * @return Result successful with {@see DATA_PRODUCT_ROWS}; the single failure of this scenario is
	 *         an owner bound to more than one order.
	 */
	public function getRows(EntityType $ownerType, int $ownerId): Result
	{
		$orderIds = $this->orderBindings->findOrderIdsByOwner($ownerType, $ownerId);
		if (count($orderIds) > 1)
		{
			return (new Result())->addError(self::multipleOrdersError());
		}

		$quantities = $this->payableItems->getQuantityByRowId($ownerType, $ownerId, $orderIds[0] ?? null);

		return (new Result())->setData([
			self::DATA_PRODUCT_ROWS => $this->collectPayableRows($ownerType, $ownerId, $quantities ?? []),
		]);
	}

	/**
	 * @param array<int, float> $quantities product row id => payable quantity
	 */
	private function collectPayableRows(
		EntityType $ownerType,
		int $ownerId,
		array $quantities,
	): ProductRowCollection
	{
		$payableRows = new ProductRowCollection();
		if ($quantities === [])
		{
			return $payableRows;
		}

		$rows = $this->repository->findAllByOwner($ownerType, new ProductRowFilter($ownerId));
		foreach ($rows->getAll() as $row)
		{
			$rowId = $row->getId();
			if ($rowId !== null && isset($quantities[$rowId]))
			{
				$payableRows->add($row->setQuantity($quantities[$rowId]));
			}
		}

		return $payableRows;
	}

	/**
	 * Codeless on purpose: this is the very error the legacy action answers with, and its form is part
	 * of the behaviour this scenario reproduces rather than a choice of this layer.
	 */
	private static function multipleOrdersError(): Error
	{
		return new Error((string)Loc::getMessage('CRM_V2_PRODUCT_ROW_ERROR_MULTIPLE_ORDERS'));
	}
}
