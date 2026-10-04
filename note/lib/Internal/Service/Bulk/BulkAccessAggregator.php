<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Bulk;

use Bitrix\Note\Internal\Access\Service\DocumentAccessService;
use Bitrix\Note\Internal\Repository\RecycleBinRepository;

/**
 * [FEAT-kb2-tree-bulk-archive / P1.T2] Splits a resolved selection into documents the
 * user may act on and documents skipped for lack of access, in a single batch pass.
 *
 * Portal-admin handling is inherited from the underlying Access services (their existing
 * PortalAdmin branches) — no separate branch is introduced here.
 */
class BulkAccessAggregator
{
	public const RECYCLE_CAP_HARD_DELETE = 'canHardDelete';
	public const RECYCLE_CAP_RESTORE = 'canRestore';

	public function __construct(
		private readonly RecycleBinRepository $recycleBinRepository = new RecycleBinRepository(),
	) {}

	/**
	 * @param int[] $ids full deduplicated affected set (from SelectionResolver)
	 * @param array<int, int[]> $byCollection collectionId => documentId[] (from SelectionResolver)
	 * @param string $section one of SelectionResolver::SECTION_*
	 * @param int $requiredLevel minimum owning-collection level for active/archive actions
	 *        (archive/delete/restore/move require CollectionAccessService::LEVEL_MANAGE)
	 * @param string[] $accessCodes the acting user's access codes
	 * @param string $recycleCapability RECYCLE_CAP_* — which recycle-bin capability gates
	 *        the action; used only when $section is SECTION_RECYCLE
	 *
	 * @return array{allowedIds: int[], skippedByAccessIds: int[]}
	 */
	public function aggregate(
		array $ids,
		array $byCollection,
		string $section,
		int $requiredLevel,
		int $userId,
		array $accessCodes,
		string $recycleCapability = self::RECYCLE_CAP_HARD_DELETE,
	): array
	{
		$ids = array_values(array_unique(array_map('intval', $ids)));
		if (empty($ids))
		{
			return ['allowedIds' => [], 'skippedByAccessIds' => []];
		}

		if ($section === SelectionResolver::SECTION_RECYCLE)
		{
			return $this->aggregateRecycle($ids, $userId, $recycleCapability);
		}

		return $this->aggregateActive($ids, $byCollection, $requiredLevel, $userId, $accessCodes);
	}

	/**
	 * @param int[] $ids
	 * @param array<int, int[]> $byCollection
	 * @param string[] $accessCodes
	 * @return array{allowedIds: int[], skippedByAccessIds: int[]}
	 */
	private function aggregateActive(
		array $ids,
		array $byCollection,
		int $requiredLevel,
		int $userId,
		array $accessCodes,
	): array
	{
		$documents = [];
		foreach ($byCollection as $collectionId => $documentIds)
		{
			foreach ($documentIds as $documentId)
			{
				$documents[] = ['id' => (int)$documentId, 'collectionId' => (int)$collectionId];
			}
		}

		$levels = DocumentAccessService::batchGetEffectiveLevels($documents, $accessCodes, $userId);

		$allowed = [];
		$skipped = [];
		foreach ($ids as $id)
		{
			if ((int)($levels[$id] ?? 0) >= $requiredLevel)
			{
				$allowed[] = $id;
			}
			else
			{
				$skipped[] = $id;
			}
		}

		return ['allowedIds' => $allowed, 'skippedByAccessIds' => $skipped];
	}

	/**
	 * @param int[] $ids
	 * @return array{allowedIds: int[], skippedByAccessIds: int[]}
	 */
	private function aggregateRecycle(array $ids, int $userId, string $recycleCapability): array
	{
		$recordsByDocumentId = $this->recycleBinRepository->getByDocumentIds($ids);
		$acl = DocumentAccessService::bulkComputeRecycleBinAcl($userId, array_values($recordsByDocumentId));

		$allowed = [];
		$skipped = [];
		foreach ($ids as $id)
		{
			$record = $recordsByDocumentId[$id] ?? null;
			$recordId = $record?->getId();
			// A missing recycle record or a false capability both mean "no access" here.
			if ($recordId !== null && ($acl[$recordId][$recycleCapability] ?? false) === true)
			{
				$allowed[] = $id;
			}
			else
			{
				$skipped[] = $id;
			}
		}

		return ['allowedIds' => $allowed, 'skippedByAccessIds' => $skipped];
	}
}
