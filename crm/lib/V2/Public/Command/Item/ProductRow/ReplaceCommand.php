<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\Item\ProductRow;

use Bitrix\Crm\V2\Internal\Service\ProductRow\Handler\ReplaceProductRowsCommandHandler;
use Bitrix\Crm\V2\Public\Command\Item\AbstractItemCommand;
use Bitrix\Crm\V2\Public\Entity\Item\ProductRow;
use Bitrix\Crm\V2\Public\Entity\Item\ProductRowCollection;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\Provider\Item\ProductRow\ProductRowProvider;
use Bitrix\Main\Result;

/**
 * Replaces the whole product row set of an Item with the one given. The only command of this group
 * that rewrites the set: everything the owner had and the command does not carry is removed, and an
 * empty set is the ordinary way to clear the composition, not an error.
 *
 * ### Rows
 *
 * Every row is a field set of the same shape the single-row commands take: the public field names of
 * {@see ProductRow} mapped to built-in PHP values. `id` is among the names that are not writable, so
 * a row identifier in the input is refused with a validation error naming the row rather than being
 * accepted or dropped in silence - the set carries what the rows **are**, never which rows they used
 * to be. `ownerId`, `ownerEntityType` and `productTypeId` are refused the same way, and so is any
 * name the type does not have. A row left without a single writable field fails the command too:
 * silently dropping it out of the set would mean deleting it.
 *
 * Row identifiers do not survive a change, and that is an observable part of this contract: rows are
 * matched against the ones already stored by equality of their fields, so a row that changed in any
 * of them is removed and created anew with a new id, only an exactly repeated row keeps its id, and
 * two identical rows sent against one stored row collapse into one. A row holding a reservation is
 * never recognised as repeated and is replaced together with its reservation.
 *
 * ### Rights and result
 *
 * The right to write the set is the right to **update** its owner, and it is checked always: neither
 * {@see AbstractItemCommand::setScope()} nor {@see AbstractItemCommand::withoutPermissionCheck()}
 * can switch that check off - they reach only the owner update pipeline behind it.
 *
 * On success {@see run()} answers with the resulting set of the owner as a {@see ProductRowCollection}
 * under {@see DATA_PRODUCT_ROWS} - the caller has to see the assigned identifiers and the outcome of
 * normalization, not a mere sign of success. On failure the errors carry structured codes; an absent
 * or unreadable owner is reported with {@see ProductRowProvider::ERROR_NOT_FOUND} /
 * {@see ProductRowProvider::ERROR_ACCESS_DENIED}. A set that fails to normalize is refused whole:
 * nothing of it reaches the owner. Every outcome of the scenario is an answer of the result: an
 * exception out of this command means a broken installation, not a request that could not be met.
 *
 * Example:
 * ```php
 * $result = (new ReplaceCommand(EntityType::deal(), 42, [['productId' => 7, 'quantity' => 2.0]], $userId))
 *     ->setScope(Scope::Rest)
 *     ->run()
 * ;
 * $rows = $result->isSuccess() ? $result->getData()[ReplaceCommand::DATA_PRODUCT_ROWS] : null;
 * ```
 */
final class ReplaceCommand extends AbstractItemCommand
{
	/** Result data key - the resulting {@see ProductRowCollection} of the owner. */
	public const DATA_PRODUCT_ROWS = ProductRowProvider::DATA_PRODUCT_ROWS;

	/**
	 * @param array<int|string, array<string, mixed>> $rows field sets in the shape
	 *        {@see AddCommand} takes, keyed by whatever the caller reports rows by - the key comes
	 *        back in the errors of a row. An empty set clears the composition.
	 */
	public function __construct(
		private readonly EntityType $ownerType,
		private readonly int $ownerId,
		private readonly array $rows,
		int $userId,
	)
	{
		parent::__construct($userId);
	}

	public function getOwnerType(): EntityType
	{
		return $this->ownerType;
	}

	public function getOwnerId(): int
	{
		return $this->ownerId;
	}

	/**
	 * @return array<int|string, array<string, mixed>>
	 */
	public function getRows(): array
	{
		return $this->rows;
	}

	protected function execute(): Result
	{
		return (new ReplaceProductRowsCommandHandler())->handle($this);
	}
}
