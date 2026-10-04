<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Document\Position;

use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Note\Internal\Access\PortalAdmin;
use Bitrix\Note\Internal\Access\Service\CollectionAccessService;
use Bitrix\Note\Internal\Model\Document;
use Bitrix\Note\Infrastructure\Agent\Access\SubtreeAclReconcileScheduler;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Service\Access\SubtreeAclReconciler;

final class PositionService
{
	private const LOCK_TIMEOUT = 5;

	// [P4.T2] Distinct from a generic access-denied so the client can explain that the move would open
	// the branch to a subtree-share audience and needs moderator level.
	public const ERROR_MOVE_ACCESS_ESCALATION = 'NOTE_MOVE_ACCESS_ESCALATION';

	private readonly DocumentRepository $repository;
	private readonly BranchManager $branchManager;
	private readonly SubtreeAclReconciler $subtreeAclReconciler;

	public function __construct(
		?DocumentRepository $repository = null,
		?BranchManager $branchManager = null,
		?SubtreeAclReconciler $subtreeAclReconciler = null
	)
	{
		$this->repository = $repository ?? new DocumentRepository();
		$this->branchManager = $branchManager ?? new BranchManager($this->repository);
		// Reparenting a wide subtree under a covering source can push the widen past the sync threshold;
		// the queue sink hands that widen to the durable agent (P4.T6), matching the save-path. Narrowing
		// stays synchronous and atomic inside the move transaction.
		$this->subtreeAclReconciler = $subtreeAclReconciler
			?? new SubtreeAclReconciler($this->repository, new SubtreeAclReconcileScheduler());
	}

	public function move(
		int $documentId,
		int $targetCollectionId,
		?int $targetParentId,
		?int $targetPosition,
		int $userId
	): Result
	{
		if ($documentId <= 0 || $targetCollectionId <= 0 || $userId <= 0)
		{
			return $this->createErrorResult('Invalid move parameters.');
		}

		$document = $this->repository->getMetaById($documentId, ['ID', 'COLLECTION_ID', 'PARENT_ID']);
		if ($document === null)
		{
			return $this->createDocumentResult(null);
		}

		if (!$this->isValidParent($targetParentId, $targetCollectionId))
		{
			return $this->createErrorResult('Parent document does not belong to the target collection.');
		}

		$sourceCollectionId = $document->getCollectionId();
		$isReparent = !$this->isSameParent($document->getParentId(), $targetParentId)
			|| $sourceCollectionId !== $targetCollectionId;

		$subtreeIds = null;
		if ($isReparent)
		{
			$subtreeIds = $this->repository->getSubtreeIds($documentId, $sourceCollectionId, true);
			if ($targetParentId !== null && in_array($targetParentId, $subtreeIds, true))
			{
				return $this->createErrorResult('Target parent belongs to moved subtree.');
			}

			// [P4.T2] Block a silent escalation: a reparent into a subtree-shared branch that would
			// hand new positive access to a share audience needs moderator level, not just manage.
			if ($this->movementEscalatesAccess($targetParentId, $targetCollectionId, $subtreeIds, $userId))
			{
				return $this->createEscalationErrorResult();
			}
		}

		$lockKeys = $this->buildOrderedLockKeys([
			['collectionId' => $sourceCollectionId, 'parentId' => $document->getParentId()],
			['collectionId' => $targetCollectionId, 'parentId' => $targetParentId],
		]);

		return $this->runMoveWithLocks(
			$lockKeys,
			$document,
			$targetCollectionId,
			$targetParentId,
			$targetPosition,
			$userId,
			$subtreeIds,
		);
	}

	public function reorder(int $collectionId, ?int $parentId, array $orderedIds, int $userId): Result
	{
		if ($collectionId <= 0 || $userId <= 0)
		{
			return $this->createErrorResult('Invalid reorder parameters.');
		}

		$lockKeys = $this->buildOrderedLockKeys([
			['collectionId' => $collectionId, 'parentId' => $parentId],
		]);

		return $this->runReorderWithLocks($lockKeys, $collectionId, $parentId, $orderedIds, $userId);
	}

	private function runMoveWithLocks(
		array $lockKeys,
		Document $document,
		int $targetCollectionId,
		?int $targetParentId,
		?int $targetPosition,
		int $userId,
		?array $precomputedSubtreeIds = null
	): Result
	{
		$acquiredLocks = $this->acquireLocks($lockKeys);
		if ($acquiredLocks === null)
		{
			return $this->createErrorResult('Unable to acquire document position lock.');
		}

		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$result = $this->moveDocument(
				$document,
				$targetCollectionId,
				$targetParentId,
				$targetPosition,
				$userId,
				$precomputedSubtreeIds,
			);
			if (!$result->isSuccess())
			{
				$connection->rollbackTransaction();

				return $result;
			}

			$connection->commitTransaction();

			return $result;
		}
		catch (\Throwable)
		{
			try
			{
				$connection->rollbackTransaction();
			}
			catch (\Throwable)
			{
			}

			return $this->createErrorResult('Document position operation failed.');
		}
		finally
		{
			$this->releaseLocks($acquiredLocks);
		}
	}

	private function runReorderWithLocks(
		array $lockKeys,
		int $collectionId,
		?int $parentId,
		array $orderedIds,
		int $userId
	): Result
	{
		$acquiredLocks = $this->acquireLocks($lockKeys);
		if ($acquiredLocks === null)
		{
			return $this->createErrorResult('Unable to acquire document position lock.');
		}

		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$result = $this->branchManager->reorderBranch($collectionId, $parentId, $orderedIds, $userId);
			if (!$result->isSuccess())
			{
				$connection->rollbackTransaction();

				return $result;
			}

			$connection->commitTransaction();

			return $result;
		}
		catch (\Throwable)
		{
			try
			{
				$connection->rollbackTransaction();
			}
			catch (\Throwable)
			{
			}

			return $this->createErrorResult('Document position operation failed.');
		}
		finally
		{
			$this->releaseLocks($acquiredLocks);
		}
	}

	private function moveDocument(
		Document $document,
		int $targetCollectionId,
		?int $targetParentId,
		?int $targetPosition,
		int $userId,
		?array $precomputedSubtreeIds = null
	): Result
	{
		$sourceCollectionId = $document->getCollectionId();
		$sourceParentId = $document->getParentId();

		if ($sourceCollectionId === $targetCollectionId && $this->isSameParent($sourceParentId, $targetParentId))
		{
			return $this->branchManager->moveInsideBranch(
				$document,
				$targetCollectionId,
				$targetParentId,
				$targetPosition,
				$userId,
			);
		}

		$result = $this->branchManager->moveBetweenBranches(
			$document,
			$sourceCollectionId,
			$sourceParentId,
			$targetCollectionId,
			$targetParentId,
			$targetPosition,
			$userId,
			$precomputedSubtreeIds,
		);
		if (!$result->isSuccess())
		{
			return $result;
		}

		// [P4.T2/T5] Re-align derived rows of the moved subtree inside the move transaction so the
		// narrowing DELETE is atomic with the reparent. Uses the source-collection subtree ids
		// (stable across the reparent) computed in move().
		$subtreeIds = $precomputedSubtreeIds
			?? $this->repository->getSubtreeIds((int)$document->getId(), $sourceCollectionId, true);
		$this->subtreeAclReconciler->syncOnMove(
			(int)$document->getId(),
			$targetParentId,
			$targetCollectionId,
			$subtreeIds,
		);

		return $result;
	}

	private function acquireLocks(array $lockKeys): ?array
	{
		$connection = Application::getConnection();
		$acquiredLocks = [];

		foreach ($lockKeys as $lockKey)
		{
			if (!$connection->lock($lockKey, self::LOCK_TIMEOUT))
			{
				$this->releaseLocks($acquiredLocks);

				return null;
			}

			$acquiredLocks[] = $lockKey;
		}

		return $acquiredLocks;
	}

	private function buildOrderedLockKeys(array $branches): array
	{
		$keys = [];
		foreach ($branches as $branch)
		{
			$collectionId = (int)($branch['collectionId'] ?? 0);
			$parentId = $branch['parentId'] ?? null;
			$parentKey = $parentId === null ? 'null' : (string)(int)$parentId;
			$keys[] = 'note_document_branch_' . $collectionId . '_' . $parentKey;
		}

		$keys = array_values(array_unique($keys));
		sort($keys, SORT_STRING);

		return $keys;
	}

	private function createDocumentResult(?Document $document): Result
	{
		$result = new Result();
		$result->setData(['document' => $document]);

		return $result;
	}

	private function createErrorResult(string $message): Result
	{
		$result = new Result();
		$result->addError(new Error($message));

		return $result;
	}

	private function createEscalationErrorResult(): Result
	{
		$result = new Result();
		$result->addError(new Error(
			'Moving here would open the branch to a subtree share; moderator level is required.',
			self::ERROR_MOVE_ACCESS_ESCALATION,
		));

		return $result;
	}

	/**
	 * [P4.T2] True when the reparent would grant new positive access AND the actor lacks moderator
	 * level on the target collection. Admins bypass; a target branch with no covering source never
	 * escalates, so ordinary moves stay at manage-level authorisation.
	 *
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

	private function isSameParent(?int $leftParentId, ?int $rightParentId): bool
	{
		return ($leftParentId === null && $rightParentId === null)
			|| ($leftParentId !== null && $rightParentId !== null && $leftParentId === $rightParentId)
		;
	}

	private function isValidParent(?int $parentId, int $collectionId): bool
	{
		if ($parentId === null || $parentId <= 0)
		{
			return true;
		}

		$parent = $this->repository->getMetaById($parentId, ['ID', 'COLLECTION_ID']);

		return $parent !== null && (int)$parent->getCollectionId() === $collectionId;
	}

	private function releaseLocks(array $lockKeys): void
	{
		if (empty($lockKeys))
		{
			return;
		}

		$connection = Application::getConnection();
		foreach (array_reverse($lockKeys) as $lockKey)
		{
			$connection->unlock($lockKey);
		}
	}
}
