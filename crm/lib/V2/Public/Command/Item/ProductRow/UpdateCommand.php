<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\Item\ProductRow;

use Bitrix\Crm\V2\Internal\Service\ProductRow\Handler\UpdateProductRowCommandHandler;
use Bitrix\Crm\V2\Public\Command\Item\AbstractItemCommand;
use Bitrix\Crm\V2\Public\Entity\Item\ProductRow;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\Provider\Item\ProductRow\ProductRowProvider;
use Bitrix\Main\Result;

/**
 * Changes one product row of an Item. The rows next to it are not rewritten - not their type, their
 * tax, their discount, their measure nor their external code.
 *
 * ### Addressing
 *
 * The row is addressed by its own id, and the owner it is written within is resolved from the row
 * itself, so the right is always checked on the real parent. The owner type says which parent the
 * row was addressed **through**: a row belonging to an Item of another type is not reachable this
 * way and is reported as missing. Passing it is what keeps a caller working with deals from reaching
 * the rows of a quote by guessing an id.
 *
 * ### Fields
 *
 * Only the fields present in the set are written - this is a partial write, and a field left out
 * keeps its value rather than being emptied. The names are the public field names of
 * {@see ProductRow} with built-in PHP values; `id`, `ownerId`, `ownerEntityType` and `productTypeId`
 * are the address of the row or the catalog's decision and are not writable, and an unknown or
 * read-only name fails the command with a validation error raised by the scenario, uniformly with
 * the other write commands of this group. An empty field set is a validation error too: there is
 * nothing to change.
 *
 * ### Rights and result
 *
 * The right to write a row is the right to **update** its owner, and it is checked always: neither
 * {@see AbstractItemCommand::setScope()} nor {@see AbstractItemCommand::withoutPermissionCheck()}
 * can switch that check off - they reach only the owner update pipeline behind it.
 *
 * On success {@see run()} answers with the row as it is after the write, normalization included,
 * under {@see DATA_PRODUCT_ROW}. On failure the errors carry structured codes; a row that is absent,
 * addressed through the wrong parent or owned by an Item the user may not update is reported with
 * {@see ProductRowProvider::ERROR_NOT_FOUND} / {@see ProductRowProvider::ERROR_ACCESS_DENIED}. Every
 * outcome of the scenario is an answer of the result: an exception out of this command means a broken
 * installation, not a request that could not be met.
 *
 * Example:
 * ```php
 * $result = (new UpdateCommand(EntityType::deal(), $rowId, ['quantity' => 3.0], $userId))
 *     ->setScope(Scope::Rest)
 *     ->run()
 * ;
 * $row = $result->isSuccess() ? $result->getData()[UpdateCommand::DATA_PRODUCT_ROW] : null;
 * ```
 */
final class UpdateCommand extends AbstractItemCommand
{
	/** Result data key - the changed {@see ProductRow}. */
	public const DATA_PRODUCT_ROW = ProductRowProvider::DATA_PRODUCT_ROW;

	/**
	 * @param EntityType $ownerType the type of Item the row is addressed through.
	 * @param array<string, mixed> $fields writable public field names of {@see ProductRow} => values;
	 *        only the names present are written.
	 */
	public function __construct(
		private readonly EntityType $ownerType,
		private readonly int $rowId,
		private readonly array $fields,
		int $userId,
	)
	{
		parent::__construct($userId);
	}

	public function getOwnerType(): EntityType
	{
		return $this->ownerType;
	}

	public function getRowId(): int
	{
		return $this->rowId;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getFields(): array
	{
		return $this->fields;
	}

	protected function execute(): Result
	{
		return (new UpdateProductRowCommandHandler())->handle($this);
	}
}
