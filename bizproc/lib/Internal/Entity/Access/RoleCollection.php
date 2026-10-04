<?php

namespace Bitrix\Bizproc\Internal\Entity\Access;

use Bitrix\Bizproc\Internal\Entity\BaseEntityCollection;

/**
 * @method \Bitrix\Bizproc\Internal\Entity\Access\Role|null getFirstCollectionItem()
 * @method \ArrayIterator<Role> getIterator()
 */
class RoleCollection extends BaseEntityCollection
{
	public function __construct(Role ...$roles)
	{
		foreach ($roles as $role)
		{
			$this->collectionItems[] = $role;
		}
	}
}
