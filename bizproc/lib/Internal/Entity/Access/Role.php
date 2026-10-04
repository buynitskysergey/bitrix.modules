<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Entity\Access;

use Bitrix\Bizproc\Internal\Entity\EntityInterface;

class Role implements EntityInterface
{
	private ?int $id = null;
	private ?string $name = null;

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

	public function getName(): ?string
	{
		return $this->name;
	}

	public function setName(?string $name): self
	{
		$this->name = $name;

		return $this;
	}

	public static function mapFromArray(array $props): static
	{
		$result = new self();

		if (isset($props['id']))
		{
			$result->setId((int)$props['id']);
		}

		if (isset($props['name']))
		{
			$result->setName((string)$props['name']);
		}

		return $result;
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'name' => $this->name,
		];
	}
}
