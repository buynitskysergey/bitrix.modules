<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Read;

use Bitrix\Main\Type\DateTime;

/**
 * What a REST answer about a single document is built from.
 *
 * It lives under REST rather than in the public provider space because it has exactly one consumer and
 * one reason to exist - the shape of the REST answer. Keeping it in the domain made the domain format
 * dates for a caller it knows nothing about.
 */
final class DocumentReadModel
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
