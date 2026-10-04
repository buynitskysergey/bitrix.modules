<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Activity\Dto\Complex;

use Bitrix\Main\Type\Contract\Arrayable;

final class AvailableBlock implements Arrayable, \JsonSerializable
{
	public function __construct(
		public readonly bool $available,
		public readonly ?array $constraints = null,
	) {}

	public static function fromArray(array $data): self
	{
		return new self(
			available: (bool)($data['available'] ?? false),
			constraints: isset($data['constraints']) && is_array($data['constraints'])
				? $data['constraints']
				: null,
		);
	}

	public function toArray(): array
	{
		return [
			'available' => $this->available,
			'constraints' => $this->constraints,
		];
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}
}
