<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\Item\ProductRow;

use Bitrix\Crm\V2\Internal\Service\ProductRow\Handler\DeleteProductRowCommandHandler;
use Bitrix\Crm\V2\Public\Command\Item\AbstractItemCommand;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\Provider\Item\ProductRow\ProductRowProvider;
use Bitrix\Main\Result;

/**
 * Removes one product row from an Item. The rows next to it stay as they are - the set is not
 * rebuilt around the gap.
 *
 * ### Addressing
 *
 * The row is addressed by its own id, and the owner it is removed from is resolved from the row
 * itself, so the right is always checked on the real parent. The owner type says which parent the
 * row was addressed **through**: a row belonging to an Item of another type is not reachable this
 * way and is reported as missing.
 *
 * ### Rights and result
 *
 * The right to remove a row is the right to **update** its owner, and it is checked always: neither
 * {@see AbstractItemCommand::setScope()} nor {@see AbstractItemCommand::withoutPermissionCheck()}
 * can switch that check off - they reach only the owner update pipeline behind it.
 *
 * A successful {@see run()} carries no data: the row it names no longer exists, and answering with
 * its former state would be answering with something that cannot be read back. Success is the whole
 * answer, and it means the row is gone and the owner has been saved - the outcome of the owner
 * update is part of it and is never dropped. On failure the errors carry structured codes; a row
 * that is absent, addressed through the wrong parent or owned by an Item the user may not update is
 * reported with {@see ProductRowProvider::ERROR_NOT_FOUND} /
 * {@see ProductRowProvider::ERROR_ACCESS_DENIED}. Every outcome of the scenario is an answer of the
 * result: an exception out of this command means a broken installation, not a request that could not
 * be met.
 *
 * Example:
 * ```php
 * $result = (new DeleteCommand(EntityType::deal(), $rowId, $userId))
 *     ->setScope(Scope::Rest)
 *     ->run()
 * ;
 * ```
 */
final class DeleteCommand extends AbstractItemCommand
{
	/**
	 * @param EntityType $ownerType the type of Item the row is addressed through.
	 */
	public function __construct(
		private readonly EntityType $ownerType,
		private readonly int $rowId,
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

	protected function execute(): Result
	{
		return (new DeleteProductRowCommandHandler())->handle($this);
	}
}
