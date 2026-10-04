<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Activity\Dto\Complex;

use Bitrix\Main\Type\Contract\Arrayable;

/**
 * Typed DTO for a single action entry in the node capability catalog.
 * Replaces raw associative arrays in NodeCapabilityCatalog::$actions.
 */
final class NodeActionCatalogEntry implements Arrayable, \JsonSerializable
{
	/**
	 * @param string $id Normalized activity code (e.g. 'imnotifyactivity').
	 * @param string $title Human-readable action title.
	 * @param bool $handlesDocument Whether the action operates on a specific document.
	 * @param string|null $group Action group (Create/Edit/Assign...); null when the action has no group.
	 * @param array|null $areas Reserved for action dimension: areas. null in MVP.
	 * @param array|null $objects Reserved for action dimension: objects. null in MVP.
	 * @param array|null $sources Reserved for action dimension: sources. null in MVP.
	 * @param array|null $parameters Reserved for action dimension: parameters. null in MVP.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $title,
		public readonly bool $handlesDocument,
		public readonly ?string $group = null,
		public readonly ?array $areas = null,
		public readonly ?array $objects = null,
		public readonly ?array $sources = null,
		public readonly ?array $parameters = null,
	) {}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'title' => $this->title,
			'handlesDocument' => $this->handlesDocument,
			'group' => $this->group,
			'areas' => $this->areas,
			'objects' => $this->objects,
			'sources' => $this->sources,
			'parameters' => $this->parameters,
		];
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}
}
