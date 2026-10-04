<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Access\Install;

use Bitrix\Bizproc\Internal\Access\Install\Role\BizprocAdmin;
use Bitrix\Bizproc\Internal\Access\Install\Role\Manager;
use Bitrix\Bizproc\Internal\Access\Role\RoleDictionary;

final class RoleMap
{
	/**
	 * @return array<string, class-string<Role\Base>> role code => role definition class
	 */
	public static function getDefaultMap(): array
	{
		return [
			RoleDictionary::ROLE_BIZPROC_ADMIN => BizprocAdmin::class,
			RoleDictionary::ROLE_MANAGER => Manager::class,
		];
	}
}
