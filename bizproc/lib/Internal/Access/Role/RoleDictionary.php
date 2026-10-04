<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Access\Role;

use Bitrix\Main\Access\Role\RoleDictionary as MainRoleDictionary;

final class RoleDictionary extends MainRoleDictionary
{
	public const ROLE_BIZPROC_ADMIN = 'BIZPROC_ACCESS_ROLE_ADMIN';
	public const ROLE_MANAGER = 'BIZPROC_ACCESS_ROLE_MANAGER';
}
