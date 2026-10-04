<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Item\Operation;

use Bitrix\Crm\Item as LegacyItem;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Factory;
use Bitrix\Crm\V2\Internal\Service\Item\Mapper\ItemFieldMapper;
use Bitrix\Crm\V2\Internal\Service\Item\Mapper\OperationSettingsMapper;
use Bitrix\Crm\V2\Internal\Service\Item\Mapper\ScopeContextMapper;
use Bitrix\Crm\V2\Public\Command\Item\AbstractItemCommand;
use Bitrix\Crm\V2\Public\Command\Item\AddItemCommand;
use Bitrix\Crm\V2\Public\Command\Item\UpdateItemCommand;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\Result;

/**
 * @internal
 */
final class ItemOperationRunner
{
	private readonly Container $container;

	public function __construct(?Container $container = null)
	{
		$this->container = $container ?? Container::getInstance();
	}

	public function run(
		AbstractItemCommand $command,
		?ItemOperationObserver $observer = null,
	): Result
	{
		if (
			$command instanceof UpdateItemCommand
			&& !$command->getItem()->hasChangedFields()
		)
		{
			return new Result();
		}

		try
		{
			$legacyItem = null;
			$preparedOperation = $this->prepareInternal($command, $legacyItem, ['*']);
		}
		catch (\Throwable $throwable)
		{
			$observer?->onPreparationThrowable($legacyItem, $throwable);

			throw $throwable;
		}

		return $preparedOperation->launch($observer);
	}

	public function prepare(AbstractItemCommand $command, array $fieldsToSelect = ['*']): PreparedItemOperation
	{
		$legacyItem = null;

		return $this->prepareInternal($command, $legacyItem, $fieldsToSelect);
	}

	private function prepareInternal(
		AbstractItemCommand $command,
		?LegacyItem &$legacyItem,
		array $fieldsToSelect,
	): PreparedItemOperation
	{
		return match (true)
		{
			$command instanceof AddItemCommand => $this->prepareAdd($command, $legacyItem),
			$command instanceof UpdateItemCommand => $this->prepareUpdate($command, $legacyItem, $fieldsToSelect),
			default => throw new ArgumentException('Unsupported item command'),
		};
	}

	private function prepareAdd(
		AddItemCommand $command,
		?LegacyItem &$preparedLegacyItem,
	): PreparedItemOperation
	{
		$v2Item = $command->getItem();
		$entityTypeId = $v2Item->getEntityType()->getId();
		$factory = $this->getFactory($entityTypeId);
		$legacyItem = $factory->createItem();
		$preparedLegacyItem = $legacyItem;

		ItemFieldMapper::copyToLegacy($v2Item, $legacyItem);

		$context = ScopeContextMapper::createContext($command);
		$operation = $factory->getAddOperation($legacyItem, $context);
		OperationSettingsMapper::applyCommandSettings($operation, $command);

		return new PreparedItemOperation(
			$command,
			$legacyItem,
			$operation,
			$context,
			$this->container->getUserPermissions($command->getUserId()),
		);
	}

	private function prepareUpdate(
		UpdateItemCommand $command,
		?LegacyItem &$preparedLegacyItem,
		array $fieldsToSelect,
	): PreparedItemOperation
	{
		$v2Item = $command->getItem();
		$itemId = $v2Item->getId();
		if ($itemId === null)
		{
			throw new ArgumentException('Cannot update item without ID');
		}

		$entityTypeId = $v2Item->getEntityType()->getId();
		$factory = $this->getFactory($entityTypeId);
		$legacyItem = $factory->getItem($itemId, $fieldsToSelect);
		if ($legacyItem === null)
		{
			throw new ObjectNotFoundException("Item not found: {$entityTypeId}:{$itemId}");
		}
		$preparedLegacyItem = $legacyItem;

		ItemFieldMapper::copyChangedToLegacy($v2Item, $legacyItem);

		$context = ScopeContextMapper::createContext($command);
		$operation = $factory->getUpdateOperation($legacyItem, $context);
		OperationSettingsMapper::applyCommandSettings($operation, $command);

		return new PreparedItemOperation(
			$command,
			$legacyItem,
			$operation,
			$context,
			$this->container->getUserPermissions($command->getUserId()),
		);
	}

	private function getFactory(int $entityTypeId): Factory
	{
		$factory = $this->container->getFactory($entityTypeId);
		if ($factory === null)
		{
			throw new ObjectNotFoundException("Factory not found for entity type: {$entityTypeId}");
		}

		return $factory;
	}
}
