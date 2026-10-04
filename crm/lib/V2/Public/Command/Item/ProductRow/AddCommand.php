<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\Item\ProductRow;

use Bitrix\Crm\V2\Internal\Service\ProductRow\Handler\AddProductRowCommandHandler;
use Bitrix\Crm\V2\Public\Command\Item\AbstractItemCommand;
use Bitrix\Crm\V2\Public\Entity\Item\ProductRow;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\Provider\Item\ProductRow\ProductRowProvider;
use Bitrix\Main\Result;

/**
 * Adds one product row to an Item, leaving the rows already there untouched.
 *
 * The owner is named by the command itself - a product row exists only within an Item, and the type
 * of that Item is a decision of the caller, never of the payload.
 *
 * ### Fields
 *
 * The row is given as a field set: the public field names of {@see ProductRow} mapped to their
 * values, built-in PHP types throughout. Four names are the address of the row rather than its
 * content and are not writable - `id`, `ownerId`, `ownerEntityType` and `productTypeId`, the last of
 * which the catalog decides; passing any of them, or a name the type does not have, fails the
 * command with a validation error. That refusal happens in the scenario and is the same in every
 * write command of this group, so no caller has to know a whitelist of its own.
 *
 * A field set is what makes a partial write expressible at all: only the names present are written,
 * and "left alone" is therefore something else than "set to an empty value".
 *
 * ### Rights and result
 *
 * The right to write a row is the right to **update** its owner, and it is checked always: neither
 * {@see AbstractItemCommand::setScope()} nor {@see AbstractItemCommand::withoutPermissionCheck()}
 * can switch that check off - they reach only the owner update pipeline behind it.
 *
 * On success {@see run()} answers with the added row under {@see DATA_PRODUCT_ROW}. On failure the
 * errors carry structured codes; an absent or unreadable owner is reported with
 * {@see ProductRowProvider::ERROR_NOT_FOUND} / {@see ProductRowProvider::ERROR_ACCESS_DENIED}, the
 * same two codes the read side speaks. Every outcome of the scenario is an answer of the result: an
 * exception out of this command means a broken installation, not a request that could not be met.
 *
 * Example:
 * ```php
 * $result = (new AddCommand(EntityType::deal(), 42, ['productId' => 7, 'quantity' => 2.0], $userId))
 *     ->setScope(Scope::Rest)
 *     ->run()
 * ;
 * $row = $result->isSuccess() ? $result->getData()[AddCommand::DATA_PRODUCT_ROW] : null;
 * ```
 */
final class AddCommand extends AbstractItemCommand
{
	/** Result data key - the added {@see ProductRow}. */
	public const DATA_PRODUCT_ROW = ProductRowProvider::DATA_PRODUCT_ROW;

	/**
	 * @param array<string, mixed> $fields writable public field names of {@see ProductRow} => values.
	 */
	public function __construct(
		private readonly EntityType $ownerType,
		private readonly int $ownerId,
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

	public function getOwnerId(): int
	{
		return $this->ownerId;
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
		return (new AddProductRowCommandHandler())->handle($this);
	}
}
