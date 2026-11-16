<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Provider\Dto;

use Bitrix\Main\Type\DateTime;

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
		// Dates travel as objects: whoever answers with them decides how they are written down, and for
		// REST that is the canon's own serializer.
		public readonly DateTime $createdAt,
		public readonly DateTime $updatedAt,
		// Moment the returned markdown was built; null until the projection materializes.
		public readonly ?DateTime $contentUpdatedAt = null,
		public readonly bool $isArchived = false,
		public readonly bool $isTrashed = false,
		public readonly bool $isOrphan = false,
	) {}
}
