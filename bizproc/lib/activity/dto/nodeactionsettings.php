<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Activity\Dto;

use Bitrix\Bizproc\Activity\Dto\Complex\ActionObject;
use Bitrix\Bizproc\Activity\Enum\ActionArea;
use Bitrix\Bizproc\Activity\Enum\ActionGroup;
use Bitrix\Main\Type\Contract\Arrayable;

/**
 * Typed form of the activity's `nodeActionSettings` declaration (flat fields).
 * Built by the pattern of the neighbouring NodeSettings DTO. Keeps the legacy
 * HANDLES_DOCUMENT key working alongside the new classification keys.
 */
final class NodeActionSettings implements Arrayable, \JsonSerializable
{
	/** @param list<ActionObject> $objects */
	public function __construct(
		public readonly bool $handlesDocument = false,
		public readonly ?ActionGroup $group = null,
		public readonly ?ActionArea $area = null,
		public readonly array $objects = [],
		public readonly bool $createsDocument = false,
	) {}

	public static function fromArray(array $data): self
	{
		// Structural, fail-closed parse: an invalid enum string coerces to null (no throw).
		$group = isset($data['ACTION_GROUP']) ? ActionGroup::tryFrom((string)$data['ACTION_GROUP']) : null;
		$area = isset($data['ACTION_AREA']) ? ActionArea::tryFrom((string)$data['ACTION_AREA']) : null;

		$objects = [];
		if ($area !== null)
		{
			foreach (($data['ACTION_OBJECTS'] ?? []) as $object)
			{
				if (is_array($object) && isset($object['id']))
				{
					$objects[] = ActionObject::fromArray($object, $area);
				}
			}
		}

		return new self(
			handlesDocument: (bool)($data['HANDLES_DOCUMENT'] ?? false),
			group: $group,
			area: $area,
			objects: $objects,
			createsDocument: (bool)($data['CREATES_DOCUMENT'] ?? false),
		);
	}

	public function toArray(): array
	{
		return [
			'HANDLES_DOCUMENT' => $this->handlesDocument,
			'ACTION_GROUP' => $this->group?->value,
			'ACTION_AREA' => $this->area?->value,
			'ACTION_OBJECTS' => array_map(static fn(ActionObject $o) => $o->toArray(), $this->objects),
			'CREATES_DOCUMENT' => $this->createsDocument,
		];
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}
}
