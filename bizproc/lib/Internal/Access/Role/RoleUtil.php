<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Access\Role;

use Bitrix\Bizproc\Internal\Model\PermissionTable;
use Bitrix\Bizproc\Internal\Model\RoleRelationTable;
use Bitrix\Bizproc\Internal\Model\RoleTable;
use Bitrix\Main\Access\Role\RoleUtil as MainRoleUtil;

final class RoleUtil extends MainRoleUtil
{
	protected static function getRoleTableClass(): string
	{
		return RoleTable::class;
	}

	protected static function getRoleRelationTableClass(): string
	{
		return RoleRelationTable::class;
	}

	protected static function getPermissionTableClass(): string
	{
		return PermissionTable::class;
	}

	protected static function getRoleDictionaryClass(): ?string
	{
		return RoleDictionary::class;
	}
}
