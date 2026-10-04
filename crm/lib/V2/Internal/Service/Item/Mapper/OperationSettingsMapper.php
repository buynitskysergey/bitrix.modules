<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Item\Mapper;

use Bitrix\Crm\Service\Operation;
use Bitrix\Crm\V2\Public\Command\Item\AbstractItemCommand;

/**
 * Applies V2 command settings to legacy Operation.
 * @internal
 */
class OperationSettingsMapper
{
	public static function applyCommandSettings(Operation $operation, AbstractItemCommand $command): void
	{
		if (!$command->shouldCheckPermissions())
		{
			$operation->disableCheckAccess();
		}

		if (!$command->shouldCheckRequiredUserFields())
		{
			$operation->disableCheckRequiredUserFields();
		}

		if (!$command->shouldRunAutomation())
		{
			$operation->disableAutomation();
			$operation->disableBizProc();
		}
	}
}
