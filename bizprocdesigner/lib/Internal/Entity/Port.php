<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Entity;


use Bitrix\BizprocDesigner\Internal\Enum\PortDirection;
use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\Type\Contract\Arrayable;

final class Port implements EntityInterface, Arrayable
{
	public function __construct(
		public readonly string $id = '',
		public PortDirection $direction = PortDirection::Output,
		public int $position = 0,
		public readonly ?string $title = null,
	) {}

	public static function createFromArray(array $data, PortDirection $direction = PortDirection::Output): self
	{
		return new Port(
			(string)($data['id'] ?? ''),
			$direction,
			(int)($data['position'] ?? ''),
			isset($data['title']) ? (string)$data['title'] : null,
		);
	}

	public function toArray(): array
	{
		$result = [
			'id' => $this->id,
			'position' => $this->position,
		];

		// Emit the title only when set, so ports without one (simple nodes) keep the {id, position} shape.
		if ($this->title !== null)
		{
			$result['title'] = $this->title;
		}

		return $result;
	}

	public function getId(): string
	{
		return $this->id;
	}
}
