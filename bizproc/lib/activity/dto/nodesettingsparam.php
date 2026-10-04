<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Activity\Dto;

use Bitrix\Main\Type\Contract\Arrayable;

final class NodeSettingsParam implements Arrayable, \JsonSerializable
{
	public function __construct(
		public readonly string $key,
		public readonly mixed $value,
	)
	{}

	public function toArray(): array
	{
		return [
			$this->key => $this->normalizeValue($this->value),
		];
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}

	private function normalizeValue(mixed $value): mixed
	{
		if ($value instanceof Arrayable)
		{
			return $value->toArray();
		}

		if ($value instanceof \JsonSerializable)
		{
			return $value->jsonSerialize();
		}

		if (is_array($value))
		{
			foreach ($value as $key => $item)
			{
				$value[$key] = $this->normalizeValue($item);
			}
		}

		return $value;
	}
}
