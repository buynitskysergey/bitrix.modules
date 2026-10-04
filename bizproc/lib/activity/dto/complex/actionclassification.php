<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Activity\Dto\Complex;

use Bitrix\Bizproc\Activity\Dto\NodeActionSettings;
use Bitrix\Bizproc\Activity\Enum\ActionArea;
use Bitrix\Bizproc\Activity\Enum\ActionGroup;
use Bitrix\Main\Type\Contract\Arrayable;

/**
 * A single collected classification row (internal, derived form built by ActionCatalogMap).
 * Outward transport happens via NodeActionCatalogEntry, not this VO.
 */
final class ActionClassification implements Arrayable, \JsonSerializable
{
	/** @param list<ActionObject> $objects */
	public function __construct(
		public readonly string $activityCode,
		public readonly ActionGroup $group,
		public readonly ActionArea $area,
		public readonly array $objects = [],
	) {}

	/**
	 * Builds a classification from already typed settings. Returns null when the settings
	 * carry no complete classification (missing group or area).
	 */
	public static function fromNodeActionSettings(string $activityCode, NodeActionSettings $settings): ?self
	{
		if ($settings->group === null || $settings->area === null)
		{
			return null;
		}

		return new self($activityCode, $settings->group, $settings->area, $settings->objects);
	}

	public function toArray(): array
	{
		return [
			'activityCode' => $this->activityCode,
			'group' => $this->group->value,
			'area' => $this->area->value,
			'objects' => array_map(static fn(ActionObject $o) => $o->toArray(), $this->objects),
		];
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}
}
