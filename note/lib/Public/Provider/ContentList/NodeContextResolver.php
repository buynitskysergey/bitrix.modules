<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Provider\ContentList;

use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Note\Internal\Access\Service\CollectionAccessService;
use Bitrix\Note\Internal\Access\Service\DocumentAccessService;
use Bitrix\Note\Internal\Model\RecycleBinTable;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Repository\RecycleBinRepository;
use Bitrix\Note\Internal\Service\RecycleBin\RetentionDeadline;

/**
 * The part of the open-document context this feature adds: how much lies directly inside the
 * document. No new route is introduced - a deleted or archived document already opens through the
 * ordinary document route and its state already arrives with the context; what was missing is what
 * the screen inside a node shows around the editor.
 *
 * The dates of the deletion travel with it: the screen inside a deleted node warns with both the
 * moment it was deleted and the moment the cleanup agent takes it away, and neither is derivable on
 * the client - the retention is a server setting.
 *
 * The children counted are direct and in the same state as the node: a whole branch walked per card
 * is too expensive on MySQL 5.6, and the number the reader needs is how many cards the first
 * expansion opens.
 */
final class NodeContextResolver
{
	public function __construct(
		private readonly DocumentRepository $documentRepository = new DocumentRepository(),
		private readonly RecycleBinRepository $recycleBinRepository = new RecycleBinRepository(),
	) {}

	/**
	 * @return array{
	 *   childCount: int,
	 *   hasChildren: bool,
	 *   trashedAt: ?string,
	 *   trashExpiresAt: ?string,
	 *   trashedWithCollection: bool,
	 * }
	 */
	public function resolve(int $documentId, int $userId): array
	{
		$empty = [
			'childCount' => 0,
			'hasChildren' => false,
			'trashedAt' => null,
			'trashExpiresAt' => null,
			'trashedWithCollection' => false,
		];
		if ($documentId <= 0)
		{
			return $empty;
		}

		$document = $this->documentRepository->getMetaById(
			$documentId,
			['ID', 'TITLE', 'PARENT_ID', 'COLLECTION_ID', 'CREATED_BY', 'IS_ARCHIVED'],
		);
		if ($document === null)
		{
			return $empty;
		}

		$entry = $this->recycleBinRepository->getByDocumentId($documentId);
		$trashedAt = $entry?->getTrashedAt();

		// The counter is what brings the block on screen at all, so it counts only what this reader may
		// open. Counted without the rights it announced both the existence and the number of the
		// documents nested inside: a document shared on its own, with nothing below it shared, still
		// said how many of them there were. The bin counts over its own rows, so it names the document
		// through the join; the other two count over the documents themselves.
		//
		// All three are asked by base and by parent: the children of a node live in the node's own
		// base, and that is the head of every branch index of the table.
		$collectionIds = [(int)$document->getCollectionId()];
		$childCount = match (true)
		{
			$entry !== null => (int)($this->recycleBinRepository->getChildCountMap(
				$collectionIds,
				[$documentId],
				$this->visibilityFilter($userId, 'DOCUMENT.ID', 'DOCUMENT.COLLECTION_ID'),
			)[$documentId] ?? 0),
			(bool)$document->getIsArchived() => (int)($this->documentRepository->getArchivedChildCountMap(
				$collectionIds,
				[$documentId],
				$this->visibilityFilter($userId, 'ID', 'COLLECTION_ID'),
			)[$documentId] ?? 0),
			default => (int)($this->documentRepository->getLiveChildCountMap(
				$collectionIds,
				[$documentId],
				$this->visibilityFilter($userId, 'ID', 'COLLECTION_ID'),
			)[$documentId] ?? 0),
		};

		return [
			'childCount' => $childCount,
			'hasChildren' => $childCount > 0,
			// The warning inside a deleted node names two dates: when it was deleted and when the
			// cleanup agent takes it away. The second is read from the same retention as everywhere.
			'trashedAt' => $trashedAt?->format('c'),
			'trashExpiresAt' => RetentionDeadline::forTrashedAt($trashedAt),
			// Why the document is in the bin: it went with its base, or it was thrown away on its
			// own. The warning says different things about the two, and only the row knows which.
			'trashedWithCollection' => $entry?->getOrigin() === RecycleBinTable::ORIGIN_CASCADE_COLLECTION_DELETED,
		];
	}

	/**
	 * What this reader may open, as a predicate over the query that does the counting - hence the
	 * field names from the caller. A portal administrator gets null: there is nothing to narrow for
	 * them, and standing in for that with an id list would only cost a query.
	 *
	 * The archive is let through: the counter answers inside an archived node too, and there the
	 * children it counts wear the same state as the node itself.
	 */
	private function visibilityFilter(int $userId, string $documentField, string $collectionField): ?ConditionTree
	{
		if ($userId <= 0 || DocumentAccessService::isPortalAdmin($userId))
		{
			return null;
		}

		$accessCodes = CollectionAccessService::buildUserAccessCodes($userId);

		return DocumentAccessService::buildListVisibilityFilter(
			$documentField,
			$collectionField,
			DocumentAccessService::personalCodes($accessCodes),
			DocumentAccessService::accessibleCollectionIds(
				CollectionAccessService::getAllUserLevels($accessCodes)['effective'] ?? [],
			),
			[DocumentAccessService::STATE_ARCHIVED],
		);
	}
}
