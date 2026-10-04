<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Item\Handler;

use Bitrix\Crm\V2\Internal\Service\Item\Mapper\OperationSettingsMapper;
use Bitrix\Crm\V2\Internal\Service\Item\Mapper\ScopeContextMapper;
use Bitrix\Crm\V2\Internal\Service\ItemCache;
use Bitrix\Crm\V2\Public\Command\Item\DeleteItemCommand;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\Result;

/**
 * Handles Delete commands by delegating to legacy Factory→Operation.
 * @internal
 */
class DeleteItemCommandHandler
{
	public function handle(DeleteItemCommand $command): Result
	{
		$itemId = $command->getItemId();
		$entityTypeId = $itemId->getEntityType()->getId();

		$factory = \Bitrix\Crm\Service\Container::getInstance()->getFactory($entityTypeId);
		if ($factory === null)
		{
			throw new ObjectNotFoundException("Factory not found for entity type: {$entityTypeId}");
		}

		$legacyItem = $factory->getItem($itemId->getId());
		if ($legacyItem === null)
		{
			throw new ObjectNotFoundException("Item not found: {$entityTypeId}:{$itemId->getId()}");
		}

		$context = ScopeContextMapper::createContext($command);
		$operation = $factory->getDeleteOperation($legacyItem, $context);
		OperationSettingsMapper::applyCommandSettings($operation, $command);

		$operationResult = $operation->launch();

		if ($operationResult->isSuccess())
		{
			ItemCache::getInstance()->invalidate($itemId->getEntityType(), $itemId->getId());

			return new Result();
		}

		return (new Result())->addErrors($operationResult->getErrors());
	}
}
