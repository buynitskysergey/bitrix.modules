<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Activity\Dto\Complex;

use Bitrix\Bizproc\Activity\Enum\ActionArea;
use Bitrix\Main\Type\Contract\Arrayable;

/**
 * Value object for an action object within an area (internal, derived form).
 * The object id is a stable contract string, unique within its area.
 */
final class ActionObject implements Arrayable, \JsonSerializable
{
	public function __construct(
		public readonly string $id,
		public readonly string $title,
		public readonly ActionArea $area,
	) {}

	/** Legacy array form of the declaration; the area is shared and passed by the caller. */
	public static function fromArray(array $data, ActionArea $area): self
	{
		return new self(
			(string)($data['id'] ?? ''),
			(string)($data['title'] ?? ''),
			$area,
		);
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'title' => $this->title,
			'area' => $this->area->value,
		];
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}
}
