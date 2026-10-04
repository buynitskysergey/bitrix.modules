<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Command\StorageItem\Mixins;

use Bitrix\Bizproc\Internal\Container;
use Bitrix\Bizproc\Internal\Exception\ErrorBuilder;
use Bitrix\Bizproc\Public\Command\StorageItem\StorageItemResult;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

trait ChecksDiskWriteLimitTrait
{
	protected function checkDiskWriteLimit(): ?Result
	{
		$limitsService = Container::getStorageLimitsService();
		if ($limitsService !== null && $limitsService->shouldBlockWrite())
		{
			return (new StorageItemResult())->addError(
				ErrorBuilder::build(Loc::getMessage('BIZPROC_STORAGE_ITEM_DISK_LIMIT_BLOCKED') ?? '')
			);
		}

		return null;
	}
}
