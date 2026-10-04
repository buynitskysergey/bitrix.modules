<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Infrastructure\Stepper;

use Bitrix\Bizproc\Internal\Access\Install\AccessInstaller;
use Bitrix\Bizproc\Internal\Access\PermissionCache;
use Bitrix\Bizproc\Internal\Model\RoleTable;
use Bitrix\Main\Application;
use Bitrix\Main\Update\Stepper;

/**
 * Seeds the preset access roles on update of an existing portal.
 *
 * The updater cannot seed them itself: it runs before the module files are copied, so bizproc code is
 * unavailable there. The stepper does it on a later hit, when the new files are already in place.
 * Seeding is idempotent, so a single pass is enough.
 */
final class AccessSeedStepper extends Stepper
{
	protected static $moduleId = 'bizproc';

	public function execute(array &$option): bool
	{
		if (!Application::getConnection()->isTableExists(RoleTable::getTableName()))
		{
			return self::FINISH_EXECUTION;
		}

		AccessInstaller::install();
		PermissionCache::getInstance()->clearGlobal();

		return self::FINISH_EXECUTION;
	}
}
