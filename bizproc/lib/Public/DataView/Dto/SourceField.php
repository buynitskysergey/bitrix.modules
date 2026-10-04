<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\DataView\Dto;

final class SourceField
{
	public function __construct(
		public readonly string $code,
		public readonly string $title,
		public readonly string $type,
		public readonly bool $multiple = false,
		public readonly bool $joinable = true,
		// A join key filters the non-leading source by IN() once per chunk, so only index-backed fields
		// (primary key, entity references) may serve as a key. A non-indexed field stays selectable and
		// groupable but is refused as a join key to avoid a full scan per chunk.
		public readonly bool $indexed = true,
		// The source can show this field as a label instead of its stored value (a stage code, a user
		// id, a category id). Only such a field answers the printable output modifier of a column
		// formula with a label, and only for such a field does the editor offer that modifier.
		public readonly bool $presentable = false,
	) {
	}

	public function toArray(): array
	{
		return [
			'code' => $this->code,
			'title' => $this->title,
			'type' => $this->type,
			'multiple' => $this->multiple,
			'joinable' => $this->joinable,
			'indexed' => $this->indexed,
			'presentable' => $this->presentable,
		];
	}
}
