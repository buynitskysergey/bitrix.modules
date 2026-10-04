<?php

namespace Bitrix\Bizproc\Internal\Entity\Access;

use Bitrix\Bizproc\Internal\Entity\BaseEntityCollection;

/**
 * @method \Bitrix\Bizproc\Internal\Entity\Access\Permission|null getFirstCollectionItem()
 * @method \ArrayIterator<Permission> getIterator()
 */
class PermissionCollection extends BaseEntityCollection
{
	public function __construct(Permission ...$permissions)
	{
		foreach ($permissions as $permission)
		{
			$this->collectionItems[] = $permission;
		}
	}
}
