<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Provider\Item\ProductRow;

use Bitrix\Crm\V2\Internal\Repository\ProductRow\ProductRowRepository;
use Bitrix\Crm\V2\Internal\Service\ProductRow\AvailableForPaymentService;
use Bitrix\Crm\V2\Public\Entity\Item\ProductRow;
use Bitrix\Crm\V2\Public\Entity\Item\ProductRowCollection;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\Provider\Item\AccessMode;
use Bitrix\Crm\V2\Public\Provider\Item\ItemProvider;
use Bitrix\Crm\V2\Public\Provider\Item\ProductRow\Param\ProductRowFilter;
use Bitrix\Crm\V2\Public\Provider\Item\ProductRow\Param\ProductRowSort;
use Bitrix\Main\Error;
use Bitrix\Main\Provider\Params\PagerInterface;
use Bitrix\Main\Result;

/**
 * Read-side public API for the product rows of CRM Items.
 *
 * A product row carries no permissions of its own - the right is checked on its **owner**, so every
 * read resolves the owner first and only then touches rows. The owner is always a single, known
 * Item: {@see getById()} resolves it from the row itself, {@see getList()} takes it from the
 * mandatory `ownerId` of the filter, {@see getAvailableForPayment()} is given it directly. Every read
 * therefore applies the right as a condition of the read, not as a filter over an already fetched
 * page - post-filtering would break the rights, the order and the paging at once.
 *
 * The access user is fixed by the constructor and is always explicit: there is no mode in which this
 * provider resolves rights through the global current user, and no way to switch the check off.
 *
 * ### Result contract
 *
 * Every method answers with a {@see Result}:
 * - success - the payload under {@see DATA_PRODUCT_ROW} / {@see DATA_PRODUCT_ROWS};
 * - failure - exactly one error, {@see ERROR_NOT_FOUND} or {@see ERROR_ACCESS_DENIED}. The single
 *   exception is the codeless refusal of {@see getAvailableForPayment()}, described there.
 *
 * "Absent" and "not allowed" are told apart here always. Collapsing both into one answer is the
 * transport's job, not this provider's.
 *
 * A row whose owner no longer exists (the cascade deliberately does not follow the owner into the
 * recycle bin) is reported as absent: there is nothing to check the right against, and reading it
 * without a check is not an option.
 *
 * Example:
 * ```php
 * $result = (new ProductRowProvider($userId))->getList(
 *     EntityType::deal(),
 *     new ProductRowFilter(ownerId: 42),
 *     new ProductRowSort([ProductRowSort::FIELD_SORT => 'ASC']),
 *     new Pager(limit: 50),
 * );
 * $rows = $result->isSuccess() ? $result->getData()[ProductRowProvider::DATA_PRODUCT_ROWS] : null;
 * ```
 */
final class ProductRowProvider
{
	/** The row, or the owner it is read within, does not exist. */
	public const ERROR_NOT_FOUND = 'CRM_PRODUCT_ROW_NOT_FOUND';

	/** The owner exists, but the access user may not read it. */
	public const ERROR_ACCESS_DENIED = 'CRM_PRODUCT_ROW_ACCESS_DENIED';

	/**
	 * The wording of the two failures above, and the single place it is written.
	 *
	 * Not localized on purpose: neither contract of this group ever shows it. The new REST answers both
	 * codes with {@see \Bitrix\Rest\V3\Exception\EntityNotFoundException}, which builds its message from a
	 * phrase of its own, and the legacy facade replaces them with the errors it has always answered with.
	 * What is left is a PHP caller reading the result, and an internal message is what it gets - the same
	 * answer it already got here before the write side existed.
	 */
	public const MESSAGE_NOT_FOUND = 'Product row not found';

	/** @see self::MESSAGE_NOT_FOUND for why the wording is not localized. */
	public const MESSAGE_ACCESS_DENIED = 'Access to the product row owner is denied';

	/** Result data key of {@see getById()} - a single {@see ProductRow}. */
	public const DATA_PRODUCT_ROW = 'productRow';

	/** Result data key of {@see getList()} - a {@see ProductRowCollection}. */
	public const DATA_PRODUCT_ROWS = 'productRows';

	private ?ProductRowRepository $repository = null;

	public function __construct(
		private readonly int $userId,
	)
	{
	}

	/**
	 * A single row by its own id. The owner - and with it the right to read the row - is resolved
	 * from the row itself.
	 */
	public function getById(int $id): Result
	{
		$row = $this->getRepository()->findById($id);
		if ($row === null)
		{
			return self::notFound();
		}

		$ownerType = $row->getOwnerEntityType();
		$ownerId = $row->getOwnerId();
		if ($ownerType === null || $ownerId === null)
		{
			return self::notFound();
		}

		$ownerAccess = $this->checkOwnerAccess($ownerType, $ownerId);
		if (!$ownerAccess->isSuccess())
		{
			return $ownerAccess;
		}

		return (new Result())->setData([self::DATA_PRODUCT_ROW => $row]);
	}

	/**
	 * The rows of one owner. The owner type comes from the trusted route, the owner id from the
	 * filter; the page is applied in SQL, over the rows of that one owner.
	 */
	public function getList(
		EntityType $ownerType,
		ProductRowFilter $filter,
		?ProductRowSort $sort = null,
		?PagerInterface $pager = null,
	): Result
	{
		$ownerAccess = $this->checkOwnerAccess($ownerType, $filter->getOwnerId());
		if (!$ownerAccess->isSuccess())
		{
			return $ownerAccess;
		}

		return (new Result())->setData([
			self::DATA_PRODUCT_ROWS => $this->getRepository()->findAllByOwner($ownerType, $filter, $sort, $pager),
		]);
	}

	/**
	 * The rows of one owner that are still to be paid, with the payable quantity in place of the
	 * stored one. Nothing is written: the quantity is replaced on the answered rows alone.
	 *
	 * The right is the same as for any other read here - the right to read the owner. Rows the payment
	 * side knows nothing about are absent from the answer, and so is everything of an owner it cannot
	 * read the composition of: for the lead and the quote the answer is always empty.
	 *
	 * The answer carries the payable rows under {@see DATA_PRODUCT_ROWS}. Besides
	 * {@see ERROR_NOT_FOUND} and {@see ERROR_ACCESS_DENIED} this method has one more failure, and it
	 * is the single place in this provider where an error carries **no** code: an owner bound to more
	 * than one order is refused with a message alone, the form the scenario has always answered with.
	 */
	public function getAvailableForPayment(EntityType $ownerType, int $ownerId): Result
	{
		$ownerAccess = $this->checkOwnerAccess($ownerType, $ownerId);
		if (!$ownerAccess->isSuccess())
		{
			return $ownerAccess;
		}

		return (new AvailableForPaymentService())->getRows($ownerType, $ownerId);
	}

	/**
	 * Existence and readability of the owner in one read: {@see AccessMode::Restricted} returns a
	 * missing Item as `null` and an unreadable one as a data-less stub, which is exactly the two
	 * causes told apart. The category an Item permission check needs is resolved by the Item
	 * provider itself.
	 *
	 * @return Result successful when the access user may read the owner.
	 */
	private function checkOwnerAccess(EntityType $ownerType, int $ownerId): Result
	{
		if ($ownerId <= 0)
		{
			return self::notFound();
		}

		$owner = ItemProvider::forEntityType($ownerType)
			->withAccessCheck($this->userId, AccessMode::Restricted)
			->getById($ownerId, ['id'])
		;
		if ($owner === null)
		{
			return self::notFound();
		}

		return $owner->canRead() ? new Result() : self::accessDenied();
	}

	private function getRepository(): ProductRowRepository
	{
		return $this->repository ??= new ProductRowRepository();
	}

	private static function notFound(): Result
	{
		return (new Result())->addError(new Error(self::MESSAGE_NOT_FOUND, self::ERROR_NOT_FOUND));
	}

	private static function accessDenied(): Result
	{
		return (new Result())->addError(
			new Error(self::MESSAGE_ACCESS_DENIED, self::ERROR_ACCESS_DENIED),
		);
	}
}
