<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Access;

/**
 * The state an entity is answered with over REST, and the one a caller filters by.
 *
 * Deliberately shorter than {@see DocumentState}: an orphan is a trashed document whose collection is
 * gone, and that the collection is gone is what the isOrphan marker is for. From outside the document
 * is in the recycle bin and nothing else, so a fourth value here would only split the filter.
 */
enum EntityState: string
{
	case Live = 'live';
	case Archived = 'archived';
	case Trashed = 'trashed';

	public static function fromDocumentState(DocumentState $state): self
	{
		return match ($state)
		{
			DocumentState::Live => self::Live,
			DocumentState::Archived => self::Archived,
			DocumentState::Trashed, DocumentState::Orphan => self::Trashed,
		};
	}
}
