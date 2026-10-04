<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\RecycleBin;

use Bitrix\Note\Internal\Access\Service\DocumentAccessService;
use Bitrix\Note\Internal\Repository\DocumentFileLinkRepository;
use Bitrix\Note\Internal\Repository\DocumentLinkRepository;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Repository\DocumentSearchRepository;
use Bitrix\Note\Internal\Repository\DocumentUpdateRepository;
use Bitrix\Note\Internal\Repository\DocumentVersionRepository;
use Bitrix\Note\Internal\Repository\DocumentViewRepository;
use Bitrix\Note\Internal\Repository\EventAuthorRepository;
use Bitrix\Note\Internal\Repository\EventRepository;
use Bitrix\Note\Internal\Repository\FavoriteRepository;
use Bitrix\Note\Internal\Repository\ImportMapRepository;
use Bitrix\Note\Internal\Repository\RecycleBinRepository;
use Bitrix\Note\Internal\Repository\SubscriptionRepository;
use Bitrix\Note\Internal\Repository\UnresolvedMentionRepository;
use Bitrix\Note\Internal\Service\Access\SubtreeAclReconciler;
use Bitrix\Note\Internal\Service\Link\DocumentLinkIndexService;
use Bitrix\Note\Internal\Service\Search\SearchIndexService;

class HardDeleteService
{
	public function __construct(
		private readonly DocumentRepository $documentRepository = new DocumentRepository(),
		private readonly DocumentUpdateRepository $documentUpdateRepository = new DocumentUpdateRepository(),
		private readonly DocumentFileLinkRepository $documentFileLinkRepository = new DocumentFileLinkRepository(),
		private readonly ImportMapRepository $importMapRepository = new ImportMapRepository(),
		private readonly RecycleBinRepository $recycleBinRepository = new RecycleBinRepository(),
		private readonly UnresolvedMentionRepository $unresolvedMentionRepository = new UnresolvedMentionRepository(),
		private readonly EventRepository $eventRepository = new EventRepository(),
		private readonly EventAuthorRepository $eventAuthorRepository = new EventAuthorRepository(),
		private readonly DocumentVersionRepository $documentVersionRepository = new DocumentVersionRepository(),
		private readonly DocumentViewRepository $documentViewRepository = new DocumentViewRepository(),
		private readonly SubscriptionRepository $subscriptionRepository = new SubscriptionRepository(),
		private readonly FavoriteRepository $favoriteRepository = new FavoriteRepository(),
		private readonly ?DocumentSearchRepository $documentSearchRepository = null,
		private readonly SearchIndexService $searchIndexService = new SearchIndexService(),
		private readonly SubtreeAclReconciler $subtreeAclReconciler = new SubtreeAclReconciler(),
		private readonly DocumentLinkRepository $documentLinkRepository = new DocumentLinkRepository(),
		private readonly DocumentLinkIndexService $documentLinkIndexService = new DocumentLinkIndexService(),
	) {}

	/**
	 * Cascade hard-delete for the given document ids — DB-only side effects.
	 *
	 * Order is fixed to avoid FK-style logical conflicts: dependents first, then the document,
	 * then the recycle-bin entry. Non-transactional side effects (CFile, search index) are
	 * intentionally NOT performed here — the caller MUST invoke {@see runPostCommitCleanup()}
	 * after a successful commit so a rollback never leaves orphaned files or a stale index.
	 *
	 * Transaction is the caller's responsibility (per MODULE.md).
	 *
	 * @param int[] $documentIds
	 * @return array{fileIds: int[], documentIds: int[]} payload to feed into runPostCommitCleanup()
	 */
	public function deleteByDocumentIds(array $documentIds): array
	{
		$normalized = array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $documentIds),
			static fn(int $id): bool => $id > 0,
		)));
		if (empty($normalized))
		{
			return ['fileIds' => [], 'documentIds' => []];
		}

		$fileIds = $this->collectFileIds($normalized);

		// History cascade: this is the ONLY place b_note_event/b_note_event_author are ever
		// deleted (TTL cleanup does not touch them) — co-authors must go before their events.
		$eventIds = $this->eventRepository->getIdsByDocumentIds($normalized);
		$this->eventAuthorRepository->deleteByEventIds($eventIds);
		$this->eventRepository->deleteByDocumentIds($normalized);
		$this->documentVersionRepository->deleteByDocumentIds($normalized);
		$this->documentViewRepository->deleteByDocumentIds($normalized);
		// [P6.T1] Subscriptions targeting the removed documents themselves — ancestor
		// subtree subscriptions elsewhere in the tree are unaffected (they never
		// referenced these ids).
		$this->subscriptionRepository->deleteByDocumentIds($normalized);
		// [P3.T2] Favorite rows of every user pointing at the removed documents. Only physical
		// deletion clears them: the recycle bin and the archive keep the row and its position.
		$this->favoriteRepository->deleteByDocumentIds($normalized);

		$this->documentFileLinkRepository->deleteByDocumentIds($normalized);
		$this->unresolvedMentionRepository->deleteByDocumentIds($normalized);
		$this->documentUpdateRepository->deleteByDocumentIds($normalized);
		$this->importMapRepository->deleteByDocumentIds($normalized);
		DocumentAccessService::deleteByDocumentIds($normalized);
		// [P4.T4/T5] Separate cascade step: any hard-deleted document may itself be a subtree source,
		// whose DERIVED rows live on descendants and are addressed by SOURCE_DOCUMENT_ID — deleting by
		// DOCUMENT_ID above never reaches them. Routed through the reconciler choke-point.
		$this->subtreeAclReconciler->revokeSources($normalized);

		// [P2.T5 / C3] Both ends of the link index. Collect the targets these sources point at BEFORE
		// the rows go — after the delete there is nobody left to name — then drop every outgoing row of
		// the removed sources in one statement and every incoming row pointing at them. This path must
		// NOT lean on content-repair the way an ordinary edit does: the source is being physically
		// removed, so a row left behind here would never be cleared. The batch DELETE runs inside the
		// caller's transaction and its failure propagates (rolling the delete back), never swallowed.
		// Only physical deletion clears the index; archiving and the recycle bin are reversible and are
		// filtered on the read instead.
		$affectedTargets = $this->documentLinkRepository->collectTargetIdsBySources($normalized);
		$this->documentLinkRepository->deleteBySourceIds($normalized);
		$this->documentLinkRepository->deleteByTargetIds($normalized);
		// One deduplicated fan-out over the union of affected targets (dispatched after commit), instead
		// of a signal per removed source.
		$this->documentLinkIndexService->notifyBacklinksChanged($affectedTargets);

		$this->documentRepository->deleteByIds($normalized);
		$this->recycleBinRepository->deleteByDocumentIds($normalized);

		return ['fileIds' => $fileIds, 'documentIds' => $normalized];
	}

	/**
	 * Best-effort post-commit cleanup of non-transactional side effects: file storage and
	 * search index. Must be called only AFTER the surrounding DB transaction has committed,
	 * otherwise a rollback would resurrect DB rows whose files/index entries are already gone.
	 *
	 * @param int[] $fileIds
	 * @param int[] $documentIds
	 */
	public function runPostCommitCleanup(array $fileIds, array $documentIds): void
	{
		foreach ($fileIds as $fileId)
		{
			try
			{
				\CFile::Delete((int)$fileId);
			}
			catch (\Throwable)
			{
			}
		}

		if (!empty($documentIds))
		{
			try
			{
				$this->searchIndexService->deindexDocuments($documentIds);
			}
			catch (\Throwable)
			{
			}
		}
	}

	/**
	 * @param int[] $documentIds
	 * @return int[]
	 */
	private function collectFileIds(array $documentIds): array
	{
		$rows = $this->documentFileLinkRepository->getByDocumentIds($documentIds);
		$ids = [];
		foreach ($rows as $row)
		{
			$fileId = (int)($row['FILE_ID'] ?? 0);
			if ($fileId > 0)
			{
				$ids[$fileId] = true;
			}
		}

		return array_keys($ids);
	}
}
