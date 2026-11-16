<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Dto\ContentList;

/**
 * State a knowledge base, a document or a path segment is in. The same three values answer for all
 * three: the recycle bin, the archive and the search all show the same objects, and a card that
 * migrates between those surfaces must not change its vocabulary on the way.
 */
enum ContentState: string
{
	case Live = 'live';
	case Archived = 'archived';
	case Trashed = 'trashed';
}
