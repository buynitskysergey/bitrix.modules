<?php

namespace Bitrix\Rest\V3\Dto\DynamicEnum;

final class DynamicEnumDefinition
{
	/** @var array<string, true> */
	private array $membership = [];

	/**
	 * @param list<string|int> $values
	 */
	public function __construct(
		public readonly DynamicEnumType $type,
		public readonly array $values,
	) {
		foreach ($values as $value)
		{
			$this->membership[$this->membershipKey($value)] = true;
		}
	}

	public function contains(mixed $value): bool
	{
		if ($this->type === DynamicEnumType::Int && !is_int($value))
		{
			return false;
		}

		if ($this->type === DynamicEnumType::String && !is_string($value))
		{
			return false;
		}

		return isset($this->membership[$this->membershipKey($value)]);
	}

	private function membershipKey(string|int $value): string
	{
		return match ($this->type)
		{
			DynamicEnumType::String => 's:' . $value,
			DynamicEnumType::Int => 'i:' . $value,
		};
	}
}
