<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Access\Install\Role;

use Bitrix\Bizproc\Internal\Access\Permission\PermissionDictionary;

final class Manager extends Base
{
	public function getPermissions(): array
	{
		return [
			PermissionDictionary::BIZPROC_TEMPLATE_CREATE,
			PermissionDictionary::BIZPROC_TEMPLATE_EDIT,
		];
	}
}
