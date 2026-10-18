<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\CheckList;

use Bitrix\Main\Result;
use Bitrix\Tasks\CheckList\Task\TaskCheckListFacade;
use Bitrix\Tasks\V2\Internal\Service\CheckList\Result\CheckListStatusResult;

class TaskCheckListCompletionFacade extends TaskCheckListFacade
{
	private static bool $hasDeferredUpdates = false;

	public static function runDeferred(int $taskId, int $userId, array $itemsFields, callable $callback): mixed
	{
		$previousItems = static::$oldItemsToMerge;
		self::$hasDeferredUpdates = false;
		static::doMergePreActions($taskId, $userId);
		static::$oldItemsToMerge = static::indexItemsById($itemsFields);
		static::enableDeferredActionsMode();

		$callbackResult = null;
		try
		{
			$callbackResult = $callback();

			return $callbackResult;
		}
		finally
		{
			try
			{
				if (
					self::$hasDeferredUpdates
					&& (
						!($callbackResult instanceof Result)
						|| $callbackResult->isSuccess()
						|| (
							$callbackResult instanceof CheckListStatusResult
							&& $callbackResult->hasChanges()
						)
					)
				)
				{
					static::doMergePostActions($taskId, $userId, ['PARAMETERS' => []]);
					static::flushTimeline($taskId);
				}
			}
			finally
			{
				static::disableDeferredActionsMode();
				static::$oldItemsToMerge = $previousItems;
				self::$hasDeferredUpdates = false;
			}
		}
	}

	private static function indexItemsById(array $itemsFields): array
	{
		$itemsById = [];
		foreach ($itemsFields as $id => $fields)
		{
			$itemId = (int)($fields['ID'] ?? $id);
			$itemsById[$itemId] = $fields;
		}

		return $itemsById;
	}

	protected static function onAfterUpdate(int $taskId): void
	{
		if (static::getDeferredActionsMode())
		{
			self::$hasDeferredUpdates = true;

			return;
		}

		parent::onAfterUpdate($taskId);
	}

	protected static function flushTimeline(int $taskId): void
	{
		parent::onAfterUpdate($taskId);
	}
}
