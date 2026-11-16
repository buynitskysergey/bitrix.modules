<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\RecycleBin;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\Note\Internal\Access\PortalAdmin;
use Bitrix\Note\Internal\Access\Service\CollectionAccessService;
use Bitrix\Note\Internal\Entity\RecycleBin\RecycleBinRecord;
use Bitrix\Note\Internal\Exceptions\OrphanRestoreTargetRequiredException;
use Bitrix\Note\Infrastructure\Agent\Access\SubtreeAclReconcileScheduler;
use Bitrix\Note\Internal\Model\Collection;
use Bitrix\Note\Internal\Model\DocumentTable;
use Bitrix\Note\Internal\Repository\CollectionRepository;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Repository\RecycleBinRepository;
use Bitrix\Note\Internal\Service\Access\SubtreeAclReconciler;
use Bitrix\Note\Internal\Service\Document\Position\PositionCalculator;
use Bitrix\Note\Internal\Service\Document\Position\PositionService;

class RestoreFromRecycleBinService
{
	public const ERROR_DOCUMENT_MISSING = 'NOTE_RESTORE_DOCUMENT_MISSING';
	public const ERROR_COLLECTION_TRASHED = 'NOTE_RESTORE_COLLECTION_TRASHED';

	public function __construct(
		private readonly RecycleBinRepository $recycleBinRepository = new RecycleBinRepository(),
		private readonly DocumentRepository $documentRepository = new DocumentRepository(),
		private readonly CollectionRepository $collectionRepository = new CollectionRepository(),
		private readonly PositionCalculator $positionCalculator = new PositionCalculator(),
		// Restoring a whole subtree reparents it via syncOnMove; a wide restore can push the widen past
		// the sync threshold, so the queue sink defers it to the durable agent (P4.T6) like move/save.
		private readonly SubtreeAclReconciler $subtreeAclReconciler = new SubtreeAclReconciler(null, new SubtreeAclReconcileScheduler()),
	) {}

	/**
	 * Restores a single recycle-bin entry. The hint dictates whether the document returns to
	 * archived state (IS_ARCHIVED='Y') or to live; PARENT_ID is preserved whenever the original
	 * parent is alive (not archived, not in bin) AND already sits in the target collection. For a
	 * whole-subtree / whole-collection restore this rebuilds the tree: the parent is restored first
	 * (ancestors-first ordering upstream), so its restored child re-attaches to it instead of
	 * falling to the collection root — even when restoring orphans into a new target collection.
	 *
	 * If the original collection is gone and $targetCollectionId is null,
	 * OrphanRestoreTargetRequiredException is thrown — the caller (controller) must surface
	 * the orphan-restore picker.
	 *
	 */
	public function restore(
		RecycleBinRecord $record,
		?int $targetCollectionId,
		int $userId,
	): Result
	{
		$result = new Result();

		$documentId = $record->getDocumentId();
		$document = $this->documentRepository->getMetaById($documentId);
		if ($document === null)
		{
			$result->addError(new Error('Document missing', self::ERROR_DOCUMENT_MISSING));

			return $result;
		}

		$wasArchivedBeforeTrash = $document->getIsArchived();
		$originalCollectionId = (int)$document->getCollectionId();
		$pickedCollectionId = $targetCollectionId ?? $originalCollectionId;
		$collection = $this->collectionRepository->getById($pickedCollectionId);
		$this->assertCollectionRestorable($collection);

		// Not the orphan branch: the row is there and the base comes back whole, so a document pulled
		// out of it now would be missing from the restored base.
		if ($collection->getTrashedAt() !== null)
		{
			$result->addError(new Error(
				'The knowledge base is in the recycle bin; it is restored as a whole.',
				self::ERROR_COLLECTION_TRASHED,
			));

			return $result;
		}

		$collectionId = (int)$collection->getId();

		$originalParentId = $document->getParentId() !== null ? (int)$document->getParentId() : null;

		$parentId = null;
		if ($originalParentId !== null)
		{
			// Re-attach to the original parent when it is alive and already lives in the target
			// collection. The collection-match check keeps cross-collection restores safe: a parent
			// restored first into the target (whole-subtree case) matches; a parent still sitting in
			// a different collection does not, so the child falls to the target root.
			$parent = $this->documentRepository->getMetaById($originalParentId);
			if (
				$parent !== null
				&& !$parent->getIsArchived()
				&& (int)$parent->getCollectionId() === $collectionId
				&& !$this->recycleBinRepository->isInRecycleBin((int)$parent->getId())
			)
			{
				$parentId = (int)$parent->getId();
			}
		}

		// Subtree ids are stable across the reparent; captured from the original collection before the
		// update and reused for the post-update derived-row sync (P4.T2/T5).
		$subtreeIds = $this->documentRepository->getSubtreeIds($documentId, $originalCollectionId, true);

		// Restoring into a subtree-shared branch that would open new access needs moderator
		// level — the same silent-escalation gate as a live move.
		if ($this->movementEscalatesAccess($parentId, $collectionId, $subtreeIds, $userId))
		{
			$result->addError(new Error(
				'Restoring here would open the branch to a subtree share; moderator level is required.',
				PositionService::ERROR_MOVE_ACCESS_ESCALATION,
			));

			return $result;
		}

		// Everything above only reads, and the refusals above are therefore free: the caller commits an
		// unsuccessful restore instead of rolling it back, because a nested rollback is not available
		// to it. The writes start here, and from here on the only way out is an exception.

		$position = $this->positionCalculator->calculateNextPosition(
			$this->documentRepository->getMaxPosition($collectionId, $parentId),
		);

		$update = [
			'POSITION' => $position,
			'UPDATED_AT' => new DateTime(),
			'UPDATED_BY' => $userId,
		];

		// A document trashed while live and restored after the base was archived comes back archived:
		// archiving a base does not catch what already lies in the bin, so returning it live would put
		// a live document in an archived base, where nothing shows it. The state of the base is not
		// touched either way - lifting the whole base out of the archive is a decision of its own, made
		// by a moderator, and a restore of one document is not the place to undo it.
		$restoredArchived = $wasArchivedBeforeTrash || $collection->getIsArchived();
		if ($restoredArchived && !$wasArchivedBeforeTrash)
		{
			$update['IS_ARCHIVED'] = 'Y';
			$update['ARCHIVED_AT'] = new DateTime();
			$update['ARCHIVED_BY'] = $userId;
		}
		if ($collectionId !== $originalCollectionId)
		{
			$update['COLLECTION_ID'] = $collectionId;
		}
		if ($parentId !== $originalParentId)
		{
			$update['PARENT_ID'] = $parentId;
		}

		DocumentTable::update($documentId, $update);

		$this->recycleBinRepository->deleteById((int)$record->getId());

		// Restore is an extra ENTRY of the reparent event, not a new type: route it through the
		// same choke-point so a restore under an active source materialises derived rows (widen) and a
		// restore out of a covered branch drops stale ones (narrow). Runs inside the caller's
		// transaction, so the narrowing DELETE is atomic with the reattach.
		$this->subtreeAclReconciler->syncOnMove($documentId, $parentId, $collectionId, $subtreeIds);

		$result->setData([
			'documentId' => $documentId,
			'collectionId' => $collectionId,
			'parentId' => $parentId,
			'position' => $position,
			// The state the document came back in, not the one it left in: everything the callers hang
			// on this - the push, the lifecycle event, the backlinks, RAG - is about a document that is
			// visible again, and an archived one is not.
			'restoredArchived' => $restoredArchived,
		]);

		return $result;
	}

	/**
	 * The same question restore() asks itself, answered for a whole selection without writing
	 * anything: may these records go back where the caller wants them. A restore of several records
	 * has to know that beforehand - the choice of where things land is made once for the whole
	 * operation, so it must not be discovered halfway through, with part of the selection already out
	 * of the bin.
	 *
	 * Asked for the selection and not per record on purpose: the destinations of a thousand records
	 * are a handful of bases, and reading the same base once per record made the check itself cost as
	 * much as the restore.
	 *
	 * A missing document is not refused here: restore() answers for it with an error of its own, one
	 * record at a time, and a selection is not held back by a row that is already gone.
	 *
	 * @param RecycleBinRecord[] $records
	 * @throws OrphanRestoreTargetRequiredException
	 */
	public function assertSelectionRestorable(array $records, ?int $targetCollectionId): void
	{
		if ($targetCollectionId !== null)
		{
			// One destination named for the whole operation: one read answers for all of it. A base in
			// the bin is refused like a missing one - the caller has to name another destination either way.
			$collection = $this->collectionRepository->getById($targetCollectionId);
			$this->assertCollectionRestorable($collection);
			if ($collection->getTrashedAt() !== null)
			{
				throw new OrphanRestoreTargetRequiredException();
			}

			return;
		}

		$documents = $this->documentRepository->getMetaByIds(
			array_map(static fn(RecycleBinRecord $record): int => $record->getDocumentId(), $records),
		);

		$collectionIds = [];
		foreach ($documents as $document)
		{
			$collectionIds[(int)$document->getCollectionId()] = true;
		}
		if (empty($collectionIds))
		{
			return;
		}

		// States and not the listing: a base in the archive is a destination like any other here, and
		// the listing methods would filter it out and refuse a restore that is allowed.
		$known = $this->collectionRepository->getStatesByIds(array_keys($collectionIds));
		foreach (array_keys($collectionIds) as $collectionId)
		{
			// A base in the bin is no destination either: it goes back whole, by its own action.
			if (!isset($known[$collectionId]) || $known[$collectionId]['isTrashed'])
			{
				throw new OrphanRestoreTargetRequiredException();
			}
		}
	}

	/**
	 * Rules of the destination, in one place for both entry points: restore() knows the base already
	 * and hands it over, the pre-flight above reads it first.
	 *
	 * @throws OrphanRestoreTargetRequiredException
	 */
	private function assertCollectionRestorable(?Collection $collection): void
	{
		if ($collection === null)
		{
			throw new OrphanRestoreTargetRequiredException();
		}
	}

	/**
	 * @param int[] $subtreeIds
	 */
	private function movementEscalatesAccess(
		?int $targetParentId,
		int $targetCollectionId,
		array $subtreeIds,
		int $userId
	): bool
	{
		if (PortalAdmin::isAdmin($userId))
		{
			return false;
		}

		if (!$this->subtreeAclReconciler->movementEscalatesAccess($targetParentId, $subtreeIds))
		{
			return false;
		}

		$accessCodes = CollectionAccessService::buildUserAccessCodes($userId);
		$level = CollectionAccessService::getUserLevel($targetCollectionId, $userId, $accessCodes);

		return $level < CollectionAccessService::LEVEL_MODERATE;
	}
}
