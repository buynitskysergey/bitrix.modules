<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Rest\Dto\Access;

use Bitrix\Rest\V3\Attribute\ElementType;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;

class RoleDto extends Dto
{
	public int $id;

	public string $title;

	public string $category;

	public bool $isDefault;

	#[ElementType(RoleAccessRightDto::class)]
	public DtoCollection $accessRights;

	public function toArray(bool $rawData = false): array
	{
		$result = parent::toArray($rawData);
		$result['accessRights'] = $this->accessRights->toArray();

		return $result;
	}
}
