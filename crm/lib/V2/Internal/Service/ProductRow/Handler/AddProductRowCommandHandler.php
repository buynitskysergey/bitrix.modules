<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\ProductRow\Handler;

use Bitrix\Crm\ProductRow as LegacyProductRow;
use Bitrix\Crm\V2\Internal\Service\ProductRow\ProductRowErrorCode;
use Bitrix\Crm\V2\Internal\Service\ProductRow\ProductRowNormalizer;
use Bitrix\Crm\V2\Public\Command\Item\ProductRow\AddCommand;
use Bitrix\Main\Result;

/**
 * Adds one product row to an Item.
 *
 * The primitive binds the new row to the set the owner already has instead of setting the set anew:
 * rebuilding it would blank the type, the tax rate, the discount sum and type, the tax-included flag,
 * the measure and the external code of every neighbouring row, because those fields do not survive a
 * round trip through the set. Neighbours are therefore left untouched by construction.
 *
 * The owner is named by the command, and the row carries no owner of its own: the legacy Item writes
 * the binding itself while normalizing, so the field set given to the primitive is content only.
 *
 * @internal
 */
final class AddProductRowCommandHandler extends AbstractProductRowCommandHandler
{
	public function handle(AddCommand $command): Result
	{
		$ownerType = $command->getOwnerType();
		$ownerId = $command->getOwnerId();

		$owner = $this->createOwnerResolver($command)->resolveByOwner($ownerType, $ownerId);
		if (!$owner->isSuccess())
		{
			return $owner;
		}

		$normalized = $this->getNormalizer()->normalize($command->getFields());
		if (!$normalized->isSuccess())
		{
			return $normalized;
		}

		$legacyItem = $this->loadOwnerItem($ownerType, $ownerId);
		if ($legacyItem === null)
		{
			return self::fail(ProductRowErrorCode::notFound());
		}

		$legacyRow = LegacyProductRow::createFromArray(
			$normalized->getData()[ProductRowNormalizer::DATA_FIELDS],
		);

		$addResult = $legacyItem->addToProductRows($legacyRow);
		if (!$addResult->isSuccess())
		{
			return self::withDomainCodes($addResult);
		}

		$saveResult = $this->saveOwner($legacyItem, $command, $ownerType, $ownerId);
		if (!$saveResult->isSuccess())
		{
			return $saveResult;
		}

		// the primitive kept the very object that the save has just given an id to
		return $this->readRow((int)$legacyRow->getId(), AddCommand::DATA_PRODUCT_ROW);
	}
}
