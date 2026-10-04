<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\ProductRow;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Repository\ProductRow\ProductRowRepository;
use Bitrix\Crm\V2\Public\Entity\Item\ProductRow;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\Provider\Item\AccessMode;
use Bitrix\Crm\V2\Public\Provider\Item\ItemProvider;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Resolves the owner a product row is written within and checks the right to write it.
 *
 * A product row carries no permissions of its own - the right is checked on its owner, and the right
 * to write a row is the right to **update** that owner. The access user is fixed by the constructor
 * and is always explicit: there is no mode in which this resolver falls back to the global current
 * user, and no way to switch the check off.
 *
 * "The owner is not there" and "the owner may not be updated" are told apart always, and in that
 * order: existence first, right second - so an owner that does not exist never reports a permission
 * problem, and a row addressed under the wrong parent is reported as missing rather than as
 * forbidden.
 *
 * The resolver only answers the question "may this owner be written, and which owner is it". It
 * neither loads the legacy Item, nor touches the update pipeline, nor normalizes anything: that is
 * the work of the handlers.
 *
 * @internal
 */
final class OwnerResolver
{
	/** Result data key - the {@see EntityType} of the owner. */
	public const DATA_OWNER_TYPE = 'ownerType';

	/** Result data key - the id of the owner. */
	public const DATA_OWNER_ID = 'ownerId';

	/** Result data key of {@see resolveByRowId()} - the {@see ProductRow} that was resolved. */
	public const DATA_PRODUCT_ROW = 'productRow';

	private ?ProductRowRepository $repository = null;

	public function __construct(
		private readonly int $userId,
	)
	{
	}

	/**
	 * The owner named by the request itself - the entry point of adding a row and of replacing the
	 * whole set.
	 */
	public function resolveByOwner(EntityType $ownerType, int $ownerId): Result
	{
		if ($ownerId <= 0 || !$this->ownerExists($ownerType, $ownerId))
		{
			return self::fail(ProductRowErrorCode::notFound());
		}

		if (!$this->canUpdateOwner($ownerType, $ownerId))
		{
			return self::fail(ProductRowErrorCode::accessDenied());
		}

		return (new Result())->setData([
			self::DATA_OWNER_TYPE => $ownerType,
			self::DATA_OWNER_ID => $ownerId,
		]);
	}

	/**
	 * The owner of an existing row - the entry point of changing and of deleting a single row. The
	 * owner comes from the row itself, so the right is always checked on the real parent and never
	 * on the one the caller believes in.
	 *
	 * Pass the parent the row was addressed through to have the binding enforced: a row that belongs
	 * elsewhere is reported as missing, and that answer is given before any permission check, so no
	 * one learns that such a row exists under a parent they may not update. Each expectation stands
	 * on its own - a caller that addresses a row by type alone, as the single-row write commands do,
	 * gets the type enforced without having to know the id.
	 */
	public function resolveByRowId(
		int $rowId,
		?EntityType $expectedOwnerType = null,
		?int $expectedOwnerId = null,
	): Result
	{
		$row = $rowId > 0 ? $this->getRepository()->findById($rowId) : null;
		$ownerType = $row?->getOwnerEntityType();
		$ownerId = $row?->getOwnerId();
		if ($row === null || $ownerType === null || $ownerId === null)
		{
			return self::fail(ProductRowErrorCode::notFound());
		}

		if (
			($expectedOwnerType !== null && $expectedOwnerType->getId() !== $ownerType->getId())
			|| ($expectedOwnerId !== null && $expectedOwnerId !== $ownerId)
		)
		{
			return self::fail(ProductRowErrorCode::ownerMismatch());
		}

		$owner = $this->resolveByOwner($ownerType, $ownerId);
		if (!$owner->isSuccess())
		{
			return $owner;
		}

		return $owner->setData([...$owner->getData(), self::DATA_PRODUCT_ROW => $row]);
	}

	/**
	 * Existence of the owner as the Item layer sees it. {@see AccessMode::Restricted} is what makes
	 * the two causes separable: a missing Item comes back as `null`, an unreadable one as a data-less
	 * stub, so absence is never reported as a permission problem.
	 */
	private function ownerExists(EntityType $ownerType, int $ownerId): bool
	{
		return ItemProvider::forEntityType($ownerType)
			->withAccessCheck($this->userId, AccessMode::Restricted)
			->getById($ownerId, ['id']) !== null
		;
	}

	private function canUpdateOwner(EntityType $ownerType, int $ownerId): bool
	{
		return Container::getInstance()
			->getUserPermissions($this->userId)
			->item()
			->canUpdate($ownerType->getId(), $ownerId)
		;
	}

	private function getRepository(): ProductRowRepository
	{
		return $this->repository ??= new ProductRowRepository();
	}

	private static function fail(Error $error): Result
	{
		return (new Result())->addError($error);
	}
}
