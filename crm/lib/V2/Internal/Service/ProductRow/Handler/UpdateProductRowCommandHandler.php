<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\ProductRow\Handler;

use Bitrix\Crm\V2\Internal\Service\ProductRow\OwnerResolver;
use Bitrix\Crm\V2\Internal\Service\ProductRow\ProductRowErrorCode;
use Bitrix\Crm\V2\Internal\Service\ProductRow\ProductRowNormalizer;
use Bitrix\Crm\V2\Public\Command\Item\ProductRow\UpdateCommand;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Main\Result;

/**
 * Changes one product row of an Item.
 *
 * The primitive changes that one row in place. Rebuilding the set of the owner to express a change of
 * a single row is not an option: it would blank the type, the tax rate, the discount sum and type, the
 * tax-included flag, the measure and the external code of every neighbouring row.
 *
 * The row is addressed by its own id and the owner comes from the row itself, so the right is checked
 * on the real parent; the type the command carries is the parent the row was addressed **through**,
 * and a row belonging elsewhere is reported as missing before any right is looked at.
 *
 * A row the legacy Item fails to normalize is a failed request, not a partial success: the field set
 * lives inside this single call, nothing has been stored, and the reset the legacy path performs on
 * such a row has nothing to undo here.
 *
 * @internal
 */
final class UpdateProductRowCommandHandler extends AbstractProductRowCommandHandler
{
	public function handle(UpdateCommand $command): Result
	{
		$rowId = $command->getRowId();

		$owner = $this->createOwnerResolver($command)->resolveByRowId($rowId, $command->getOwnerType());
		if (!$owner->isSuccess())
		{
			return $owner;
		}

		$normalized = $this->getNormalizer()->normalize($command->getFields());
		if (!$normalized->isSuccess())
		{
			return $normalized;
		}

		/** @var EntityType $ownerType */
		$ownerType = $owner->getData()[OwnerResolver::DATA_OWNER_TYPE];
		$ownerId = (int)$owner->getData()[OwnerResolver::DATA_OWNER_ID];

		$legacyItem = $this->loadOwnerItem($ownerType, $ownerId);
		if ($legacyItem === null)
		{
			return self::fail(ProductRowErrorCode::notFound());
		}

		$updateResult = $legacyItem->updateProductRow(
			$rowId,
			$normalized->getData()[ProductRowNormalizer::DATA_FIELDS],
		);
		if (!$updateResult->isSuccess())
		{
			return self::withDomainCodes($updateResult);
		}

		$saveResult = $this->saveOwner($legacyItem, $command, $ownerType, $ownerId);
		if (!$saveResult->isSuccess())
		{
			return $saveResult;
		}

		return $this->readRow($rowId, UpdateCommand::DATA_PRODUCT_ROW);
	}
}
