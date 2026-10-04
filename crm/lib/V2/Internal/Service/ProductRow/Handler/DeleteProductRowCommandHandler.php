<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\ProductRow\Handler;

use Bitrix\Crm\ProductRow as LegacyProductRow;
use Bitrix\Crm\ProductRowTable;
use Bitrix\Crm\V2\Internal\Service\ProductRow\OwnerResolver;
use Bitrix\Crm\V2\Internal\Service\ProductRow\ProductRowErrorCode;
use Bitrix\Crm\V2\Public\Command\Item\ProductRow\DeleteCommand;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Main\Application;
use Bitrix\Main\Result;

/**
 * Removes one product row from an Item.
 *
 * The primitive unbinds that one row. The set is not rebuilt around the gap, so the neighbouring rows
 * keep their type, tax, discount, measure and external code - the fields a rebuild would blank.
 *
 * Two things the legacy path leaves out are done here: the row is required to belong to the parent it
 * was addressed through, and the outcome of the owner update is part of the answer. The primitive
 * itself returns nothing, so the only thing that can report a refused removal is the pipeline result,
 * and dropping it would report a row as gone while it is still there.
 *
 * The pipeline result alone is not enough for that, though. An owner with nothing changed produces no
 * effects and reports success, so a removal that reached no collection to remove from answers «done»
 * over a row that never moved. Success is therefore confirmed by the storage rather than by the
 * pipeline, and a row that outlived its removal is answered as a refusal and written to the log: this
 * is an invariant of the group broken below it, and nothing else in the path would ever say so.
 *
 * A successful removal answers with no data: the row it names no longer exists.
 *
 * @internal
 */
final class DeleteProductRowCommandHandler extends AbstractProductRowCommandHandler
{
	public function handle(DeleteCommand $command): Result
	{
		$rowId = $command->getRowId();

		$owner = $this->createOwnerResolver($command)->resolveByRowId($rowId, $command->getOwnerType());
		if (!$owner->isSuccess())
		{
			return $owner;
		}

		/** @var EntityType $ownerType */
		$ownerType = $owner->getData()[OwnerResolver::DATA_OWNER_TYPE];
		$ownerId = (int)$owner->getData()[OwnerResolver::DATA_OWNER_ID];

		$legacyItem = $this->loadOwnerItem($ownerType, $ownerId);
		if ($legacyItem === null)
		{
			return self::fail(ProductRowErrorCode::notFound());
		}

		$legacyRow = self::loadLegacyRow($rowId);
		if ($legacyRow === null)
		{
			return self::fail(ProductRowErrorCode::notFound());
		}

		$legacyItem->removeFromProductRows($legacyRow);

		$saveResult = $this->saveOwner($legacyItem, $command, $ownerType, $ownerId);
		if (!$saveResult->isSuccess())
		{
			return $saveResult;
		}

		if (self::isRowStillStored($rowId))
		{
			self::reportRemovalDidNotHappen($rowId, $ownerType, $ownerId);

			return self::fail(ProductRowErrorCode::removalDidNotHappen());
		}

		return $saveResult;
	}

	/**
	 * Read by the primary key and by one column: the answer needed here is whether the row outlived its
	 * removal, not what it holds.
	 */
	private static function isRowStillStored(int $rowId): bool
	{
		return (bool)ProductRowTable::getList([
			'select' => ['ID'],
			'filter' => ['=ID' => $rowId],
			'limit' => 1,
		])->fetch();
	}

	/**
	 * The anomaly is worth a log of its own: the caller gets a refusal, but what it needs to be fixed is
	 * the owner and the row it happened on, at the moment it happened.
	 */
	private static function reportRemovalDidNotHappen(int $rowId, EntityType $ownerType, int $ownerId): void
	{
		Application::getInstance()->getExceptionHandler()->writeToLog(
			new \RuntimeException(sprintf(
				'Product row %d of %s %d outlived a removal the owner update reported as successful.',
				$rowId,
				$ownerType->getCode(),
				$ownerId,
			)),
		);
	}

	/**
	 * The primitive takes an ORM object, and the repository of this layer deliberately hands out
	 * public rows only - hence the one read here. The Item does not have its rows loaded at this
	 * point either: the primitive fills that collection itself, and the object given to it serves as
	 * the primary key to unbind, not as the instance the collection works with.
	 */
	private static function loadLegacyRow(int $rowId): ?LegacyProductRow
	{
		$row = ProductRowTable::getByPrimary($rowId)->fetchObject();

		return $row instanceof LegacyProductRow ? $row : null;
	}
}
