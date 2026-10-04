<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Rest\Dto\Access;

use Bitrix\Rest\V3\Dto\Dto;

class RoleAccessRightDto extends Dto
{
	public string $permissionId;

	public int $area;
}
