<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Item;

use Bitrix\Main\Entity\EntityInterface;

abstract class AbstractMultifieldValue implements EntityInterface
{
	public function __construct(
		private readonly string $valueType,
		private readonly string $value,
		private readonly ?int $id = null,
	)
	{
	}

	public function getId(): ?int
	{
		return $this->id;
	}

	public function getValueType(): string
	{
		return $this->valueType;
	}

	public function getValue(): string
	{
		return $this->value;
	}

	public function equals(self $other): bool
	{
		return $other::class === static::class
			&& $this->valueType === $other->valueType
			&& $this->value === $other->value;
	}
}
