<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Access;

/**
 * The state a document can be in as far as an external caller is concerned.
 *
 * A collection's main document has no member here on purpose: it is not a REST entity at all, so the
 * resolver answers about it the same way it answers about an id that was never used.
 */
enum DocumentState: string
{
	case Live = 'live';
	case Archived = 'archived';
	case Trashed = 'trashed';

	/** In the recycle bin, and the collection that owned it is gone. */
	case Orphan = 'orphan';
}
