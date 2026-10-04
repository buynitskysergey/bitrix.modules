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
	 */
	public function restore(RecycleBinRecord $record, ?int $targetCollectionId, int $userId): Result
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
		if ($collection === null)
		{
			throw new OrphanRestoreTargetRequiredException();
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

		// [P4.T2] Restoring into a subtree-shared branch that would open new access needs moderator
		// level — the same silent-escalation gate as a live move.
		if ($this->movementEscalatesAccess($parentId, $collectionId, $subtreeIds, $userId))
		{
			$result->addError(new Error(
				'Restoring here would open the branch to a subtree share; moderator level is required.',
				PositionService::ERROR_MOVE_ACCESS_ESCALATION,
			));

			return $result;
		}

		$position = $this->positionCalculator->calculateNextPosition(
			$this->documentRepository->getMaxPosition($collectionId, $parentId),
		);

		$update = [
			'POSITION' => $position,
			'UPDATED_AT' => new DateTime(),
			'UPDATED_BY' => $userId,
		];
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

		// [P4.T5] Restore is an extra ENTRY of the reparent event, not a new type: route it through the
		// same choke-point so a restore under an active source materialises derived rows (widen) and a
		// restore out of a covered branch drops stale ones (narrow). Runs inside the caller's
		// transaction, so the narrowing DELETE is atomic with the reattach.
		$this->subtreeAclReconciler->syncOnMove($documentId, $parentId, $collectionId, $subtreeIds);

		$result->setData([
			'documentId' => $documentId,
			'collectionId' => $collectionId,
			'parentId' => $parentId,
			'position' => $position,
			'wasArchivedBeforeTrash' => $wasArchivedBeforeTrash,
		]);

		return $result;
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
