<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\DataView\Dto;

final class SourceRelation
{
	public function __construct(
		public readonly string $fromEntity,
		public readonly string $fromField,
		public readonly string $toEntity,
		public readonly string $toField,
		public readonly string $title,
	) {
	}

	public function toArray(): array
	{
		return [
			'fromEntity' => $this->fromEntity,
			'fromField' => $this->fromField,
			'toEntity' => $this->toEntity,
			'toField' => $this->toField,
			'title' => $this->title,
		];
	}
}
