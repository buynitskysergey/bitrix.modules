<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Provider\ContentList;

use Bitrix\Note\Internal\Access\Service\DocumentAccessService;
use Bitrix\Note\Public\Dto\ContentList\ContentState;

/**
 * State of one knowledge base - live, archived or deleted.
 *
 * The lists answer this question for a whole page of cards at once (see PathResolver and
 * SectionProvider); an open document asks it about the single base it lies in, so that the path
 * above the text knows whether that base can be opened at all: an archived base has no page to go
 * to, and a deleted one is reached through the recycle bin only.
 */
class CollectionStateResolver
{
	public function resolve(int $collectionId): ContentState
	{
		if ($collectionId <= 0)
		{
			return ContentState::Live;
		}

		$state = DocumentAccessService::collectionState($collectionId);
		if ($state === null)
		{
			return ContentState::Live;
		}

		// Deleted wins over archived: a base archived first and deleted after is in the bin now.
		return match (true)
		{
			$state['trashed'] => ContentState::Trashed,
			$state['archived'] => ContentState::Archived,
			default => ContentState::Live,
		};
	}
}
