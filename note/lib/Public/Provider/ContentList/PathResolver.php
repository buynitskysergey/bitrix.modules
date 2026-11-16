<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Provider\ContentList;

use Bitrix\Note\Internal\Access\Service\DocumentAccessService;
use Bitrix\Note\Internal\Model\Document;
use Bitrix\Note\Internal\Repository\CollectionRepository;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Repository\RecycleBinRepository;
use Bitrix\Note\Public\Dto\ContentList\ContentState;
use Bitrix\Note\Public\Dto\ContentList\PathSegmentDto;

/**
 * Path of a card, built for a whole page at once. The existing ancestor resolver answers
 * for one document - it was written for direct opening - and running it per card would walk the
 * tree as many times as the page has rows.
 *
 * The path starts at the first segment the viewer may see and says nothing about what came before
 * it: a name alone already reveals that an object exists, so a muted placeholder would leak exactly
 * what the permission withholds. The first visible segment is the knowledge base itself when the
 * viewer can see it, and the topmost reachable ancestor otherwise.
 */
final class PathResolver
{
	public function __construct(
		private readonly DocumentRepository $documentRepository = new DocumentRepository(),
		private readonly CollectionRepository $collectionRepository = new CollectionRepository(),
		private readonly RecycleBinRepository $recycleBinRepository = new RecycleBinRepository(),
	) {}

	/**
	 * @param int[] $documentIds
	 * @param array<int, Document> $documents already-read rows of the page itself
	 * @param int[] $allowedCollectionIds knowledge bases the viewer holds VIEW+ on
	 * @return array<int, PathSegmentDto[]> documentId => path, base first
	 */
	public function resolve(
		array $documentIds,
		array $documents,
		array $allowedCollectionIds,
		bool $isAdmin,
		array $accessCodes = [],
	): array
	{
		if (empty($documentIds))
		{
			return [];
		}

		$ancestorMap = $this->documentRepository->getAncestorIdsMap($documentIds);

		$ancestorIds = [];
		foreach ($ancestorMap as $ids)
		{
			foreach ($ids as $id)
			{
				$ancestorIds[(int)$id] = true;
			}
		}

		$ancestors = [];
		foreach ($this->documentRepository->getMetaByIds(
			array_keys($ancestorIds),
			['ID', 'TITLE', 'COLLECTION_ID', 'IS_ARCHIVED'],
		) as $ancestor)
		{
			$ancestors[(int)$ancestor->getId()] = $ancestor;
		}

		$trashedAncestorIds = $this->recycleBinRepository->filterTrashedDocumentIds(array_keys($ancestorIds));

		$collectionIds = [];
		foreach ($documents as $document)
		{
			$collectionIds[(int)$document->getCollectionId()] = true;
		}
		$collections = $this->collectionRepository->getStatesByIds(array_keys($collectionIds));
		$allowed = array_flip($allowedCollectionIds);
		$grantedAncestorIds = $this->resolveGrantedAncestorIds($ancestors, $allowed, $isAdmin, $accessCodes);

		$paths = [];
		foreach ($documentIds as $documentId)
		{
			$document = $documents[$documentId] ?? null;
			if ($document === null)
			{
				continue;
			}

			$segments = [];
			$collectionId = (int)$document->getCollectionId();
			if (($isAdmin || isset($allowed[$collectionId])) && isset($collections[$collectionId]))
			{
				$state = $collections[$collectionId];
				$segments[] = PathSegmentDto::collection(
					$collectionId,
					$state['name'],
					// The same order the ancestors above are read in, and the same reason: deleted
					// wins over archived, because the bin is where the base is now.
					match (true)
					{
						$state['isTrashed'] => ContentState::Trashed,
						$state['isArchived'] => ContentState::Archived,
						default => ContentState::Live,
					},
				);
			}

			// getAncestorIdsMap walks upwards; the path reads downwards.
			foreach (array_reverse($ancestorMap[$documentId] ?? []) as $ancestorId)
			{
				$ancestor = $ancestors[(int)$ancestorId] ?? null;
				if ($ancestor === null)
				{
					continue;
				}

				// An ancestor the viewer may not see is not in the path at all - not by name and not
				// as a placeholder. Reached through a grant on a document deep inside somebody else's
				// tree, the reader used to be handed the ids and the titles of every document above it.
				if (!isset($grantedAncestorIds[(int)$ancestor->getId()]))
				{
					continue;
				}

				$segments[] = PathSegmentDto::document(
					(int)$ancestor->getId(),
					(string)$ancestor->getTitle(),
					match (true)
					{
						isset($trashedAncestorIds[(int)$ancestor->getId()]) => ContentState::Trashed,
						(bool)$ancestor->getIsArchived() => ContentState::Archived,
						default => ContentState::Live,
					},
				);
			}

			$paths[$documentId] = $segments;
		}

		return $paths;
	}

	/**
	 * Which ancestors may be named. Inside a knowledge base the viewer holds, all of them: the base
	 * is theirs. Outside it, only the ones granted to them personally - and a grant is inherited
	 * downwards, so what that leaves is the lower part of the chain, which is exactly where the path
	 * has to start.
	 *
	 * @param array<int, object> $ancestors
	 * @param array<int, int> $allowed collection ids the viewer holds, as a lookup
	 * @param string[] $accessCodes
	 * @return array<int, true>
	 */
	private function resolveGrantedAncestorIds(
		array $ancestors,
		array $allowed,
		bool $isAdmin,
		array $accessCodes,
	): array
	{
		$granted = [];
		$toCheck = [];
		foreach ($ancestors as $id => $ancestor)
		{
			if ($isAdmin || isset($allowed[(int)$ancestor->getCollectionId()]))
			{
				$granted[(int)$id] = true;

				continue;
			}

			$toCheck[] = (int)$id;
		}

		if (empty($toCheck))
		{
			return $granted;
		}

		return $granted + DocumentAccessService::filterGrantedDocumentIds(
			$toCheck,
			DocumentAccessService::personalCodes($accessCodes),
		);
	}
}
