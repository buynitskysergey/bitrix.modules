<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Entity\Access;

use Bitrix\Bizproc\Internal\Entity\EntityInterface;

class Permission implements EntityInterface
{
	private ?int $id = null;
	private ?int $roleId = null;
	private ?int $permissionId = null;
	private ?int $value = null;

	public function getId(): ?int
	{
		return $this->id;
	}

	public function isNew(): bool
	{
		return $this->id === null;
	}

	public function setId(?int $id): self
	{
		$this->id = $id;

		return $this;
	}

	public function getRoleId(): ?int
	{
		return $this->roleId;
	}

	public function setRoleId(?int $roleId): self
	{
		$this->roleId = $roleId;

		return $this;
	}

	public function getPermissionId(): ?int
	{
		return $this->permissionId;
	}

	public function setPermissionId(?int $permissionId): self
	{
		$this->permissionId = $permissionId;

		return $this;
	}

	public function getValue(): ?int
	{
		return $this->value;
	}

	public function setValue(?int $value): self
	{
		$this->value = $value;

		return $this;
	}

	public static function mapFromArray(array $props): static
	{
		$result = new self();

		if (isset($props['id']))
		{
			$result->setId((int)$props['id']);
		}

		if (isset($props['roleId']))
		{
			$result->setRoleId((int)$props['roleId']);
		}

		if (isset($props['permissionId']))
		{
			$result->setPermissionId((int)$props['permissionId']);
		}

		if (isset($props['value']))
		{
			$result->setValue((int)$props['value']);
		}

		return $result;
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'roleId' => $this->roleId,
			'permissionId' => $this->permissionId,
			'value' => $this->value,
		];
	}
}
