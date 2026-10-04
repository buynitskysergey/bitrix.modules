<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Item\Operation;

use Bitrix\Crm\Item as LegacyItem;
use Bitrix\Crm\Service\Context;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\FileUploader;
use Bitrix\Crm\Service\Operation;
use Bitrix\Crm\Service\Operation\TransactionWrapper;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\V2\Internal\Service\Item\Mapper\ItemFieldMapper;
use Bitrix\Crm\V2\Internal\Service\ItemCache;
use Bitrix\Crm\V2\Public\Command\Item\AbstractItemCommand;
use Bitrix\Crm\V2\Public\Command\Item\AddItemCommand;
use Bitrix\Crm\V2\Public\Command\Item\UpdateItemCommand;
use Bitrix\Crm\V2\Public\Entity\Item\Item;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Result;

/**
 * @internal
 */
final class PreparedItemOperation
{
	private readonly Item $v2Item;

	public function __construct(
		private readonly AbstractItemCommand $command,
		private readonly LegacyItem $legacyItem,
		private readonly Operation $operation,
		private readonly Context $context,
		private readonly UserPermissions $userPermissions,
	)
	{
		$this->v2Item = match (true)
		{
			$command instanceof AddItemCommand,
			$command instanceof UpdateItemCommand => $command->getItem(),
			default => throw new ArgumentException('Unsupported item command'),
		};
	}

	public function getCommand(): AbstractItemCommand
	{
		return $this->command;
	}

	public function getV2Item(): Item
	{
		return $this->v2Item;
	}

	public function getLegacyItem(): LegacyItem
	{
		return $this->legacyItem;
	}

	public function getOperation(): Operation
	{
		return $this->operation;
	}

	public function getContext(): Context
	{
		return $this->context;
	}

	public function getUserPermissions(): UserPermissions
	{
		return $this->userPermissions;
	}

	public function checkAccess(): Result
	{
		return $this->operation->checkAccess();
	}

	public function syncChangedFieldsFromV2Item(): void
	{
		if ($this->command instanceof AddItemCommand)
		{
			ItemFieldMapper::copyToLegacy($this->v2Item, $this->legacyItem);

			return;
		}

		ItemFieldMapper::copyChangedToLegacy($this->v2Item, $this->legacyItem);
	}

	public function launch(?ItemOperationObserver $observer = null): Result
	{
		$observer?->onBeforeLaunch($this->legacyItem);

		try
		{
			$operationResult = $this->operation->launch();
			$result = new Result();
			if (!$operationResult->isSuccess())
			{
				$result->addErrors($operationResult->getErrors());
			}
			else
			{
				ItemFieldMapper::syncFromLegacy($this->legacyItem, $this->v2Item);
				$this->v2Item->resetChangedFields();

				$itemId = $this->v2Item->getId();
				if ($itemId !== null)
				{
					ItemCache::getInstance()->invalidate($this->v2Item->getEntityType(), $itemId);
				}
			}

			$observer?->onAfterLaunch($this->legacyItem, $result);

			return $result;
		}
		catch (\Throwable $throwable)
		{
			$observer?->onThrowable($this->legacyItem, $throwable);

			throw $throwable;
		}
	}

	public function launchInTransaction(?ItemOperationObserver $observer = null): Result
	{
		$fileUploader = Container::getInstance()->getFileUploader();
		$fileUploader->beginDeferredPersistentDeletions();

		return (new TransactionWrapper($this->operation))->launchWithCallbacks(
			launcher: fn(): Result => $this->launch($observer),
			onRollback: fn(): FileUploader => $fileUploader->rollbackDeferredPersistentDeletions(),
			afterCommit: fn(): FileUploader => $fileUploader->commitDeferredPersistentDeletions(),
		);
	}
}
