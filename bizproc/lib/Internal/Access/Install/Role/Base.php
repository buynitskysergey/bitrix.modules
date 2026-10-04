<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Access\Install\Role;

use Bitrix\Bizproc\Internal\Access\Permission\PermissionDictionary;

abstract class Base
{
	/**
	 * @return int[] permission codes granted to the role
	 */
	abstract public function getPermissions(): array;

	/**
	 * @return array<int, array{permissionId: int, value: int}>
	 */
	public function getMap(): array
	{
		$result = [];
		foreach ($this->getPermissions() as $permissionId)
		{
			foreach ($this->getPermissionValue($permissionId) as $value)
			{
				$result[] = [
					'permissionId' => $permissionId,
					'value' => $value,
				];
			}
		}

		return $result;
	}

	/**
	 * @return int[]
	 */
	protected function getPermissionValue($permissionId): array
	{
		return [PermissionDictionary::getDefaultPermissionValue($permissionId)];
	}
}
