<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Provider;

use Bitrix\Main\UserTable;
use Bitrix\Note\Internal\Access\Service\CollectionAccessService;
use Bitrix\Note\Internal\Access\Service\DocumentAccessService;
use Bitrix\Note\Internal\Model\CollectionTable;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Repository\RecycleBinRepository;
use Bitrix\Note\Internal\Service\Document\DocumentCardMetaResolver;
use Bitrix\Note\Internal\Service\User\SystemUser;

final class RecycleBinProvider
{
	public function __construct(
		private readonly RecycleBinRepository $repository = new RecycleBinRepository(),
		private readonly DocumentRepository $documentRepository = new DocumentRepository(),
		private readonly DocumentCardMetaResolver $cardMetaResolver = new DocumentCardMetaResolver(),
	) {}

	/**
	 * Paginated payload for the /recycle-bin/ page.
	 *
	 * Items are ordered (TRASHED_AT DESC, ID DESC). Collection identity is read directly from
	 * the document row (it is not mutated while the document sits in the bin).
	 *
	 * Privacy: documents visible only via document-grant (no collection-VIEW) hide collectionId
	 * and collectionTitle, mirroring the /shared/ contract. Orphan rows (collection already gone)
	 * are unaffected and keep collectionId for the restore flow.
	 *
	 * An entry the reader may not view is dropped whole - title, excerpt, author, parent and the
	 * name of its base alike. The selection is deliberately wider than that: it keeps a base that
	 * is in the bin itself, which answers at MODERATE and not at VIEW, so the answer is cut by the
	 * ACL already computed here, the same way {@see ContentList\LevelProvider} cuts its levels.
	 *
	 * @param array<int, string> $accessCodes
	 * @param array{trashedAt: string, recycleBinId: int}|null $afterCursor
	 * @return array{
	 *   items: array<int, array{
	 *     id: int,
	 *     documentId: int,
	 *     title: string,
	 *     parentId: ?int,
	 *     collectionId: ?int,
	 *     collectionTitle: string,
	 *     orphan: bool,
	 *     trashedAt: string,
	 *     trashedBy: array{id: int, name: string, isSystem?: true},
	 *     origin: string,
	 *     canRestore: bool,
	 *     canHardDelete: bool,
	 *   }>,
	 *   nextCursor: ?array{trashedAt: string, recycleBinId: int}
	 * }
	 */
	public function getRecycleBinForUser(array $accessCodes, int $userId, int $limit, ?array $afterCursor = null): array
	{
		$page = DocumentAccessService::listRecycleBinRecordsForUser($userId, $accessCodes, $limit, $afterCursor);
		if (empty($page['ids']))
		{
			return ['items' => [], 'nextCursor' => $page['nextCursor']];
		}

		$records = $this->repository->getByIds($page['ids']);

		$documentIds = [];
		$actorIds = [];
		foreach ($records as $record)
		{
			$documentIds[(int)$record->getDocumentId()] = true;
			$actorIds[(int)$record->getTrashedBy()] = true;
		}

		$documents = $this->documentRepository->getMetaByIds(
			array_keys($documentIds),
			['ID', 'TITLE', 'PARENT_ID', 'COLLECTION_ID', 'CREATED_BY'],
		);
		$documentsById = [];
		$collectionIds = [];
		$authorIdByDocumentId = [];
		foreach ($documents as $document)
		{
			$documentsById[(int)$document->getId()] = $document;
			$collectionIds[(int)$document->getCollectionId()] = true;
			$authorIdByDocumentId[(int)$document->getId()] = (int)$document->getCreatedBy();
		}

		$collectionTitles = $this->resolveCollectionTitles(array_keys($collectionIds));
		$userNames = $this->resolveUserNames(array_keys($actorIds));
		$acl = DocumentAccessService::bulkComputeRecycleBinAcl($userId, array_values($records));
		$cardMeta = $this->cardMetaResolver->resolve($authorIdByDocumentId);

		$collectionLevels = !empty($collectionIds)
			? CollectionAccessService::batchGetUserLevels(
				array_keys($collectionIds),
				array_values(array_unique([...$accessCodes, '*'])),
			)
			: [];

		$items = [];
		foreach ($page['ids'] as $entryId)
		{
			$record = $records[(int)$entryId] ?? null;
			if ($record === null)
			{
				continue;
			}

			$documentId = (int)$record->getDocumentId();
			$document = $documentsById[$documentId] ?? null;
			$collectionId = $document !== null ? (int)$document->getCollectionId() : 0;
			$collectionAlive = isset($collectionTitles[$collectionId]);
			$trashedBy = (int)$record->getTrashedBy();
			$entryAcl = $acl[(int)$record->getId()]
				?? ['canView' => false, 'canRestore' => false, 'canHardDelete' => false];
			if ($entryAcl['canView'] !== true)
			{
				continue;
			}

			$meta = $cardMeta[$documentId] ?? ['excerpt' => '', 'author' => null];
			$collectionLevel = $collectionLevels[$collectionId] ?? CollectionAccessService::LEVEL_NONE;
			// Orphan rows keep collectionId for restore flow; only hide title for them.
			// Live-collection rows with document-grant-only access hide both id and title.
			$sharedAccess = $collectionAlive && $collectionLevel < CollectionAccessService::LEVEL_VIEW;
			$items[] = [
				'id' => (int)$record->getId(),
				'documentId' => $documentId,
				'title' => $document !== null ? (string)$document->getTitle() : '',
				'parentId' => $document !== null && $document->getParentId() !== null
					? (int)$document->getParentId()
					: null,
				'collectionId' => $sharedAccess ? null : $collectionId,
				'collectionTitle' => ($collectionAlive && !$sharedAccess)
					? (string)$collectionTitles[$collectionId]
					: '',
				'orphan' => !$collectionAlive,
				'trashedAt' => $record->getTrashedAt()->format('c'),
				'trashedBy' => $userNames[$trashedBy]
					?? ['id' => $trashedBy, 'name' => 'User #' . $trashedBy],
				'origin' => $record->getOrigin(),
				'canRestore' => $entryAcl['canRestore'],
				'canHardDelete' => $entryAcl['canHardDelete'],
				'excerpt' => $meta['excerpt'],
				'author' => $meta['author'],
			];
		}

		return ['items' => $items, 'nextCursor' => $page['nextCursor']];
	}

	/**
	 * Counts visible restorable entries and orphans for the bulk-restore popup.
	 *
	 * @param array<int, string> $accessCodes
	 * @return array{total: int, orphanCount: int}
	 */
	public function getStatsForUser(int $userId, array $accessCodes): array
	{
		$ids = $this->listVisibleRestorableIdsForUser($userId, $accessCodes);
		if (empty($ids))
		{
			return ['total' => 0, 'orphanCount' => 0];
		}

		$records = $this->repository->getByIds($ids);
		$documentIds = [];
		foreach ($records as $record)
		{
			$documentIds[(int)$record->getDocumentId()] = true;
		}

		$documents = $this->documentRepository->getMetaByIds(
			array_keys($documentIds),
			['ID', 'COLLECTION_ID'],
		);
		$collectionByDocument = [];
		$collectionIds = [];
		foreach ($documents as $document)
		{
			$collectionId = (int)$document->getCollectionId();
			$collectionByDocument[(int)$document->getId()] = $collectionId;
			$collectionIds[$collectionId] = true;
		}

		$aliveCollections = $this->resolveCollectionTitles(array_keys($collectionIds));

		$orphanCount = 0;
		foreach ($records as $record)
		{
			$collectionId = $collectionByDocument[(int)$record->getDocumentId()] ?? 0;
			if (!isset($aliveCollections[$collectionId]))
			{
				$orphanCount++;
			}
		}

		return [
			'total' => count($ids),
			'orphanCount' => $orphanCount,
		];
	}

	/**
	 * Iterates visible recycle-bin entries page by page and yields ids the user can restore.
	 *
	 * @param array<int, string> $accessCodes
	 * @return int[]
	 */
	public function listVisibleRestorableIdsForUser(int $userId, array $accessCodes, int $pageSize = 200): array
	{
		$out = [];
		$cursor = null;
		do
		{
			$page = DocumentAccessService::listRecycleBinRecordsForUser($userId, $accessCodes, $pageSize, $cursor);
			$ids = $page['ids'];
			if (!empty($ids))
			{
				$records = $this->repository->getByIds($ids);
				$acl = DocumentAccessService::bulkComputeRecycleBinAcl($userId, array_values($records));
				foreach ($ids as $id)
				{
					$record = $records[(int)$id] ?? null;
					if ($record === null)
					{
						continue;
					}
					if (($acl[(int)$record->getId()]['canRestore'] ?? false) === true)
					{
						$out[] = (int)$record->getId();
					}
				}
			}
			$cursor = $page['nextCursor'] ?? null;
		}
		while ($cursor !== null);

		return $out;
	}

	/**
	 * @param int[] $ids
	 * @return array<int, string>
	 */
	private function resolveCollectionTitles(array $ids): array
	{
		$ids = array_values(array_filter($ids, static fn(int $id): bool => $id > 0));
		if (empty($ids))
		{
			return [];
		}

		$rows = CollectionTable::query()
			->setSelect(['ID', 'NAME'])
			->whereIn('ID', $ids)
			->setCacheTtl(60)
			->exec();

		$out = [];
		while ($row = $rows->fetch())
		{
			$out[(int)$row['ID']] = (string)($row['NAME'] ?? '');
		}

		return $out;
	}

	/**
	 * @param int[] $ids
	 * @return array<int, array{id: int, name: string, isSystem?: true}>
	 */
	private function resolveUserNames(array $ids): array
	{
		if (empty($ids))
		{
			return [];
		}

		$out = [];
		$realIds = [];
		foreach ($ids as $id)
		{
			$id = (int)$id;
			if (SystemUser::isSystem($id))
			{
				$out[SystemUser::ID] = [
					'id' => SystemUser::ID,
					'name' => SystemUser::name(),
					'isSystem' => true,
				];
			}
			elseif ($id > 0)
			{
				$realIds[] = $id;
			}
		}

		if ($realIds === [])
		{
			return $out;
		}

		$rows = UserTable::query()
			->setSelect(['ID', 'NAME', 'LAST_NAME', 'LOGIN'])
			->whereIn('ID', $realIds)
			->setCacheTtl(300)
			->exec();

		while ($row = $rows->fetch())
		{
			$id = (int)$row['ID'];
			$name = trim(((string)($row['NAME'] ?? '')) . ' ' . ((string)($row['LAST_NAME'] ?? '')));
			if ($name === '')
			{
				$name = (string)($row['LOGIN'] ?? ('User #' . $id));
			}
			$out[$id] = ['id' => $id, 'name' => $name];
		}

		return $out;
	}
}
