<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Provider\Dto;

final class DocumentReadDto
{
	public function __construct(
		public readonly int $id,
		public readonly ?int $collectionId,
		public readonly ?int $parentId,
		public readonly string $title,
		public readonly string $markdown,
		public readonly int $position,
		public readonly int $createdBy,
		// Structural change of the document record (rename, move, archive, REST overwrite): editor
		// edits deliberately leave both alone, they show up in contentUpdatedAt below.
		public readonly int $updatedBy,
		public readonly string $createdAt,
		public readonly string $updatedAt,
		// [DTO-01] Moment the returned markdown was built; null until the projection materializes.
		public readonly ?string $contentUpdatedAt = null,
	) {}
}
