<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\ProductRow\Handler;

use Bitrix\Crm\V2\Internal\Service\ProductRow\ProductRowErrorCode;
use Bitrix\Crm\V2\Internal\Service\ProductRow\ProductRowNormalizer;
use Bitrix\Crm\V2\Public\Command\Item\ProductRow\ReplaceCommand;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\Provider\Item\ProductRow\Param\ProductRowFilter;
use Bitrix\Main\Result;

/**
 * Replaces the whole product row set of an Item with the one given.
 *
 * The only scenario allowed to rewrite the set, and the only one using the primitive that sets it:
 * everything the owner had and the command does not carry is removed, an empty set clears the
 * composition. The single-row scenarios stay away from this primitive on purpose - it would blank the
 * fields that do not survive a round trip through the set.
 *
 * The semantics of the set primitive are reproduced as they are: no row identifier is accepted from
 * the input, rows are matched against the stored ones by equality of their fields, a row that changed
 * is removed and created anew with a new id, and two identical rows sent against one stored row
 * collapse into one. A row holding a reservation is never recognised as repeated, because the reserve
 * fields take part in that comparison and the whitelist never lets them in.
 *
 * One thing differs from the legacy path, and it is the reason the order of steps below is normative:
 * the whole set is normalized **before** the legacy Item is loaded. The set primitive removes the rows
 * that were not provided as part of its own work, so touching it with a set that fails to normalize
 * would delete rows on behalf of a request that is refused anyway.
 *
 * The answer is the resulting set of the owner rather than a sign of success: the caller has to see
 * the assigned identifiers and the outcome of normalization.
 *
 * @internal
 */
final class ReplaceProductRowsCommandHandler extends AbstractProductRowCommandHandler
{
	public function handle(ReplaceCommand $command): Result
	{
		$ownerType = $command->getOwnerType();
		$ownerId = $command->getOwnerId();

		$owner = $this->createOwnerResolver($command)->resolveByOwner($ownerType, $ownerId);
		if (!$owner->isSuccess())
		{
			return $owner;
		}

		$normalized = $this->getNormalizer()->normalizeAll($command->getRows());
		if (!$normalized->isSuccess())
		{
			return $normalized;
		}

		$legacyItem = $this->loadOwnerItem($ownerType, $ownerId);
		if ($legacyItem === null)
		{
			return self::fail(ProductRowErrorCode::notFound());
		}

		$setResult = $legacyItem->setProductRowsFromArrays(
			$normalized->getData()[ProductRowNormalizer::DATA_ROWS],
		);
		if (!$setResult->isSuccess())
		{
			return self::withDomainCodes($setResult);
		}

		$saveResult = $this->saveOwner($legacyItem, $command, $ownerType, $ownerId);
		if (!$saveResult->isSuccess())
		{
			return $saveResult;
		}

		return $this->readOwnerRows($ownerType, $ownerId);
	}

	/**
	 * The set as storage has it after the write, in the natural row order - the order the read side
	 * answers with, tie-breaker included.
	 */
	private function readOwnerRows(EntityType $ownerType, int $ownerId): Result
	{
		return (new Result())->setData([
			ReplaceCommand::DATA_PRODUCT_ROWS => $this->getRepository()->findAllByOwner(
				$ownerType,
				new ProductRowFilter($ownerId),
			),
		]);
	}
}
