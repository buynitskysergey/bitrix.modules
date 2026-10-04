<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\ProductRow\Handler;

use Bitrix\Crm\Item as LegacyItem;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Factory;
use Bitrix\Crm\V2\Internal\Repository\ProductRow\ProductRowRepository;
use Bitrix\Crm\V2\Internal\Service\Item\Mapper\OperationSettingsMapper;
use Bitrix\Crm\V2\Internal\Service\Item\Mapper\ScopeContextMapper;
use Bitrix\Crm\V2\Internal\Service\ItemCache;
use Bitrix\Crm\V2\Internal\Service\ProductRow\OwnerResolver;
use Bitrix\Crm\V2\Internal\Service\ProductRow\ProductRowErrorCode;
use Bitrix\Crm\V2\Internal\Service\ProductRow\ProductRowNormalizer;
use Bitrix\Crm\V2\Public\Command\Item\AbstractItemCommand;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Main\Error;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\Result;

/**
 * The steps every product row write scenario shares, and the one place its transitional dependency
 * on the legacy Item lives.
 *
 * There are no point-wise write primitives for a product row in V2 - adding, changing and removing a
 * single row exist on the legacy Item alone - so the scenarios take the Factory, load the legacy
 * Item, call the primitive and run the update operation of the owner themselves. That compromise is
 * named here and goes no further: it is the whole reason this base class exists, and nothing outside
 * `Internal` sees it.
 *
 * The sequence itself is deliberately **not** hidden behind a template method. Its order is normative
 * - resolving the owner before normalizing, normalizing before touching the Factory, the primitive
 * before the pipeline - and changing it changes which error a caller observes. Each handler therefore
 * spells the sequence out and calls the shared steps by name, so the order is visible where it can be
 * reviewed rather than buried in a parent.
 *
 * Side effects are the business of the owner update pipeline. Nothing here writes reserves, syncs a
 * basket or records history, and nothing forces a save when there is nothing to change.
 *
 * @internal
 */
abstract class AbstractProductRowCommandHandler
{
	private ?ProductRowNormalizer $normalizer = null;

	private ?ProductRowRepository $repository = null;

	/**
	 * The access user is the one the command names, always explicitly: a write never falls back to
	 * the global current user, and {@see AbstractItemCommand::withoutPermissionCheck()} does not
	 * reach this check - it only reaches the pipeline behind it.
	 */
	final protected function createOwnerResolver(AbstractItemCommand $command): OwnerResolver
	{
		return new OwnerResolver($command->getUserId());
	}

	final protected function getNormalizer(): ProductRowNormalizer
	{
		return $this->normalizer ??= new ProductRowNormalizer();
	}

	final protected function getRepository(): ProductRowRepository
	{
		return $this->repository ??= new ProductRowRepository();
	}

	/**
	 * The legacy Item the rows are written within, or `null` when it is gone - the owner was resolved
	 * against the read side, and between that and this it may have been deleted.
	 */
	final protected function loadOwnerItem(EntityType $ownerType, int $ownerId): ?LegacyItem
	{
		return $this->getFactory($ownerType)->getItem($ownerId);
	}

	/**
	 * Runs the update operation of the owner over the changes a primitive has left on the legacy
	 * Item, and drops the cached snapshot of that Item once it succeeded.
	 *
	 * An Item with nothing changed produces no effects - an existing property of the operation, kept
	 * rather than worked around: no write is forced to make an empty change observable.
	 *
	 * @return Result data-less on success; on failure the pipeline errors with a code of this layer
	 *         attached, see {@see withDomainCodes()}.
	 */
	final protected function saveOwner(
		LegacyItem $legacyItem,
		AbstractItemCommand $command,
		EntityType $ownerType,
		int $ownerId,
	): Result
	{
		$operation = $this->getFactory($ownerType)->getUpdateOperation(
			$legacyItem,
			ScopeContextMapper::createContext($command),
		);
		OperationSettingsMapper::applyCommandSettings($operation, $command);

		$operationResult = $operation->launch();
		if (!$operationResult->isSuccess())
		{
			return self::withDomainCodes($operationResult);
		}

		ItemCache::getInstance()->invalidate($ownerType, $ownerId);

		return new Result();
	}

	/**
	 * The row as it is in storage after the write, normalization included, under the data key the
	 * command publishes.
	 */
	final protected function readRow(int $rowId, string $dataKey): Result
	{
		$row = $rowId > 0 ? $this->getRepository()->findById($rowId) : null;

		return $row === null
			? self::fail(ProductRowErrorCode::notFound())
			: (new Result())->setData([$dataKey => $row])
		;
	}

	/**
	 * Gives the errors of a legacy primitive or of the update operation a code of this layer without
	 * touching their meaning: an error that already carries one is kept as it is, and a message is
	 * never rewritten. The transport reads codes and never parses messages, so a failure without a
	 * code would be a failure it cannot tell apart from any other.
	 */
	final protected static function withDomainCodes(Result $result): Result
	{
		$errors = ProductRowErrorCode::fromPipelineErrors($result->getErrors());

		return $errors === [] ? $result : (new Result())->addErrors($errors);
	}

	final protected static function fail(Error $error): Result
	{
		return (new Result())->addError($error);
	}

	/**
	 * @throws ObjectNotFoundException an entity type that reached this point without a Factory means
	 *         a broken installation, not a request that could not be met.
	 */
	private function getFactory(EntityType $ownerType): Factory
	{
		$entityTypeId = $ownerType->getId();

		$factory = Container::getInstance()->getFactory($entityTypeId);
		if ($factory === null)
		{
			throw new ObjectNotFoundException("Factory not found for entity type: {$entityTypeId}");
		}

		return $factory;
	}
}
