<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex;

use JsonSerializable;

class ActionDictionaryEntryDto implements JsonSerializable
{
	public function __construct(
		public string $id,
		public string $title,
		public bool $handlesDocument,
		public ?string $group = null,
		public ?array $properties = null,
		public bool $isRelationCreate = false,
	) {}

	public function jsonSerialize(): array
	{
		return [
			'id' => $this->id,
			'title' => $this->title,
			'handlesDocument' => $this->handlesDocument,
			'group' => $this->group,
			'properties' => $this->properties,
			'isRelationCreate' => $this->isRelationCreate,
		];
	}
}
