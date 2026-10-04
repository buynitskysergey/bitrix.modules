<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Repository\Mapper;

use Bitrix\Bizproc\Internal\Entity\Access\Role;
use Bitrix\Bizproc\Internal\Model\EO_Role;

class AccessRoleMapper
{
	public function convertFromOrm(EO_Role $ormModel): Role
	{
		return (new Role())
			->setId($ormModel->getId())
			->setName($ormModel->getName())
		;
	}
}
