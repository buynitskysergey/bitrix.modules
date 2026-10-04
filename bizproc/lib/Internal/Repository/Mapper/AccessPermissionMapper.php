<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Repository\Mapper;

use Bitrix\Bizproc\Internal\Entity\Access\Permission;
use Bitrix\Bizproc\Internal\Model\EO_Permission;

class AccessPermissionMapper
{
	public function convertFromOrm(EO_Permission $ormModel): Permission
	{
		return (new Permission())
			->setId($ormModel->getId())
			->setRoleId($ormModel->getRoleId())
			->setPermissionId((int)$ormModel->getPermissionId())
			->setValue($ormModel->getValue())
		;
	}
}
