<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Sidebar;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Note\Internal\Access\Service\DocumentAccessService;
use Bitrix\Note\Internal\Entity\Document\BreadcrumbAncestor;
use Bitrix\Note\Internal\Entity\RecycleBin\RecycleBinRecord;
use Bitrix\Note\Internal\Model\CollectionTable;
use Bitrix\Note\Internal\Model\Document;
use Bitrix\Note\Internal\Model\DocumentTable;
use Bitrix\Note\Internal\Model\FavoriteTable;
use Bitrix\Note\Internal\Repository\CollectionRepository;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Repository\FavoriteRepository;
use Bitrix\Note\Internal\Repository\RecycleBinRepository;
use Bitrix\Note\Internal\Service\Document\DocumentBacklinksSnapshotResolver;
use Bitrix\Note\Internal\Service\Document\DocumentCardMetaResolver;
use Bitrix\Note\Internal\Service\Document\DocumentLastChangeResolver;
use Bitrix\Note\Internal\Service\Document\DocumentSubscriptionStateResolver;
use Bitrix\Note\Internal\Service\Document\DocumentViewsSnapshotResolver;
use Bitrix\Note\Public\Provider\CollaborationProvider;
class DirectOpenContextService
{
	private const MAX_PARENT_CHAIN_DEPTH = 200;

	private DocumentRepository $documentRepository;
	private CollectionRepository $collectionRepository;
	private RecycleBinRepository $recycleBinRepository;
	private DocumentCardMetaResolver $cardMetaResolver;
	private DocumentViewsSnapshotResolver $viewsSnapshotResolver;
	private DocumentLastChangeResolver $lastChangeResolver;
	private DocumentSubscriptionStateResolver $subscriptionStateResolver;
	private DocumentBacklinksSnapshotResolver $backlinksSnapshotResolver;
	private FavoriteRepository $favoriteRepository;
	public function __construct(
		?DocumentRepository $documentRepository = null,
		?CollectionRepository $collectionRepository = null,
		?RecycleBinRepository $recycleBinRepository = null,
		?DocumentCardMetaResolver $cardMetaResolver = null,
		?DocumentViewsSnapshotResolver $viewsSnapshotResolver = null,
		?DocumentLastChangeResolver $lastChangeResolver = null,
		?DocumentSubscriptionStateResolver $subscriptionStateResolver = null,
		?DocumentBacklinksSnapshotResolver $backlinksSnapshotResolver = null,
		?FavoriteRepository $favoriteRepository = null,
	)
	{
		$this->documentRepository = $documentRepository ?? new DocumentRepository();
		$this->collectionRepository = $collectionRepository ?? new CollectionRepository();
		$this->recycleBinRepository = $recycleBinRepository ?? new RecycleBinRepository();
		$this->cardMetaResolver = $cardMetaResolver ?? new DocumentCardMetaResolver();
		// [#6] Same initial "who viewed" snapshot DocumentController bundles for the plain
		// getAction bootstrap — the direct-open (sidebar deep-link) path needs it too, since
		// both feed the same DocumentEditorComponent/ActivityLineComponent tree on the client.
		$this->viewsSnapshotResolver = $viewsSnapshotResolver ?? new DocumentViewsSnapshotResolver();
		// Same bundling rationale as viewsSnapshotResolver above, for the activity-line chip's
		// "last change" (author(s) + time).
		$this->lastChangeResolver = $lastChangeResolver ?? new DocumentLastChangeResolver();
		// Same bundling rationale again, for the subscription bell state.
		$this->subscriptionStateResolver = $subscriptionStateResolver ?? new DocumentSubscriptionStateResolver();
		// [DTO-01] Same bundling once more, for the incoming-links counter. Without it the chip is
		// absent on first paint and pops in after its own request — the direct-open path feeds the
		// very same activity line as getAction.
		$this->backlinksSnapshotResolver = $backlinksSnapshotResolver ?? new DocumentBacklinksSnapshotResolver();
		$this->favoriteRepository = $favoriteRepository ?? new FavoriteRepository();
	}

	public function resolve(int $documentId): ?array
	{
		if ($documentId <= 0)
		{
			return null;
		}

		$targetDocument = $this->documentRepository->getById($documentId);

		if ($targetDocument === null)
		{
			return null;
		}

		$trashRecord = $this->recycleBinRepository->getByDocumentId($documentId);
		if ($trashRecord !== null)
		{
			$userId = $this->getCurrentUserId();
			if (!DocumentAccessService::canViewInRecycleBin($userId, $trashRecord))
			{
				return null;
			}

			$canRestore = DocumentAccessService::canRestoreFromRecycleBin($userId, $trashRecord);
			$canHardDelete = DocumentAccessService::canHardDeleteFromRecycleBin($userId, $trashRecord);

			return $this->buildTrashedContext(
				$targetDocument,
				$documentId,
				$trashRecord,
				$canRestore,
				$canHardDelete,
			);
		}

		$collectionId = $targetDocument->getCollectionId();
		if ($collectionId <= 0)
		{
			return null;
		}

		$isTargetArchived = (bool)$targetDocument->getIsArchived();
		$snapshot = DocumentAccessService::getCurrentUserSnapshot($documentId, $collectionId, $isTargetArchived);
		if (!$snapshot['canViewCollection'])
		{
			if (!$snapshot['canView'])
			{
				return null;
			}

			return $this->buildSharedAccessContext($targetDocument, $documentId, $collectionId, $snapshot);
		}
		$canEditDocument = $snapshot['canEdit'];
		$canEditCollection = $snapshot['canEditCollection'];
		$canManagePermissions = $snapshot['canManagePermissions'];

		// archived target is hidden from the sidebar tree, so skip building expansion state for it
		$shouldExpandSidebarTree = !$isTargetArchived;

		$ancestorIds = [];
		$ancestorPairs = [];
		if ($shouldExpandSidebarTree)
		{
			$ancestorDocuments = $this->documentRepository->getDocumentPathToRoot(
				$documentId,
				self::MAX_PARENT_CHAIN_DEPTH,
			);
			foreach ($ancestorDocuments as $ancestorDocument)
			{
				if (!$ancestorDocument instanceof Document || $ancestorDocument->getIsArchived())
				{
					continue;
				}

				$ancestorId = (int)$ancestorDocument->getId();
				if ($ancestorId <= 0 || $ancestorDocument->getCollectionId() !== $collectionId)
				{
					continue;
				}

				$ancestorIds[] = $ancestorId;
				$ancestorPairs[] = new BreadcrumbAncestor($ancestorId, (string)$ancestorDocument->getTitle());
			}
		}

		$expandedDocs = [];
		foreach ($ancestorIds as $ancestorId)
		{
			$expandedDocs[(string)$ancestorId] = true;
		}

		$branchParentIds = array_merge([null], $ancestorIds);
		$branchDocumentsByKey = [];
		$branchDocumentIds = [];
		$targetDocumentFromBranches = null;

		foreach ($branchParentIds as $parentId)
		{
			$branchKey = $this->branchKey($collectionId, $parentId);
			$branchDocuments = $this->documentRepository->getDocumentsByParent(
				$collectionId,
				$parentId,
			);
			$branchDocumentsByKey[$branchKey] = $branchDocuments;

			foreach ($branchDocuments as $branchDocument)
			{
				if (!$branchDocument instanceof Document)
				{
					continue;
				}

				$branchDocumentId = (int)$branchDocument->getId();
				if ($branchDocumentId <= 0)
				{
					continue;
				}

				$branchDocumentIds[$branchDocumentId] = true;
				if ($branchDocumentId === $documentId)
				{
					$targetDocumentFromBranches = $branchDocument;
				}
			}
		}

		$targetDocumentEntity = $targetDocumentFromBranches ?? $targetDocument;
		$targetDocumentId = (int)$targetDocumentEntity->getId();
		if (!$isTargetArchived && !isset($branchDocumentIds[$targetDocumentId]))
		{
			return null;
		}

		$hasChildrenMap = $this->documentRepository->getHasChildrenMap(
			$collectionId,
			array_keys($branchDocumentIds),
		);

		$authorIdByDocumentId = [];
		foreach ($branchParentIds as $parentId)
		{
			$branchKey = $this->branchKey($collectionId, $parentId);
			foreach ($branchDocumentsByKey[$branchKey] ?? [] as $child)
			{
				if (!$child instanceof Document)
				{
					continue;
				}
				$childId = (int)$child->getId();
				if ($childId <= 0 || $child->getIsArchived())
				{
					continue;
				}
				$authorIdByDocumentId[$childId] = (int)$child->getCreatedBy();
			}
		}
		$cardMeta = $this->cardMetaResolver->resolve($authorIdByDocumentId);

		$branches = [];
		foreach ($branchParentIds as $parentId)
		{
			$branchKey = $this->branchKey($collectionId, $parentId);
			$branchDocumentsSource = $branchDocumentsByKey[$branchKey] ?? [];
			$branchDocuments = [];
			foreach ($branchDocumentsSource as $child)
			{
				if (!$child instanceof Document)
				{
					continue;
				}

				$childId = (int)$child->getId();
				if ($childId <= 0 || $child->getIsArchived())
				{
					continue;
				}

				$meta = $cardMeta[$childId] ?? ['excerpt' => '', 'author' => null];
				$branchDocuments[] = [
					'id' => $childId,
					'collectionId' => $child->getCollectionId(),
					'parentId' => $this->toNullableInt($child->getParentId()),
					'title' => $child->getTitle(),
					'position' => $child->getPosition(),
					'hasChildren' => (bool)($hasChildrenMap[$childId] ?? false),
					'isArchived' => false,
					'excerpt' => $meta['excerpt'],
					'author' => $meta['author'],
				];
			}

			$branches[$branchKey] = $branchDocuments;
		}

		$collectionName = '';
		$collection = $this->collectionRepository->getById($collectionId);
		if ($collection !== null)
		{
			$collectionName = trim($collection->getName());
		}

		$normalizedCollectionName = trim($collectionName);
		if ($normalizedCollectionName === '')
		{
			$normalizedCollectionName = '#' . $collectionId;
		}

		$userId = $this->getCurrentUserId();
		// The document is read through the ORM cache here (DocumentRepository::getById), so the
		// `canEnableCollaboration` this carries is a hint; the uncached verdict rides on
		// CollaborationSyncController::loadForCollaboration.
		$collabMeta = (new CollaborationProvider())->buildCollaborationMeta(
			$targetDocumentId,
			$collectionId,
			$userId,
			$canEditDocument,
			$targetDocument->getContentFormat(),
			$targetDocument->getMaterializedUptoId(),
		);

		$targetArchivedAt = $targetDocument->getArchivedAt();
		$targetDocumentPayload = [
			'id' => $targetDocumentId,
			'collectionId' => $collectionId,
			'collectionTitle' => $normalizedCollectionName,
			'canEdit' => $canEditDocument,
			'canEditCollection' => $canEditCollection,
			'canManagePermissions' => $canManagePermissions,
			'parentId' => $this->toNullableInt($targetDocumentEntity->getParentId()),
			'title' => $targetDocumentEntity->getTitle(),
			// Mirrors DocumentController::getAction so the activity-line "Создан …" fallback
			// works on the direct-open / server-render bootstrap path, not only via getAction.
			'createdAt' => $targetDocument->getCreatedAt()->format('c'),
			'contentFormat' => $targetDocument->getContentFormat(),
			'position' => $targetDocumentEntity->getPosition(),
			'isArchived' => $isTargetArchived,
			'archivedAt' => $targetArchivedAt?->format('c'),
			'isTrashed' => false,
			'hasChildren' =>(bool)($hasChildrenMap[$targetDocumentId] ?? false),
			'ancestors' => BreadcrumbAncestor::listToArray($ancestorPairs),
			'views' => $this->viewsSnapshotResolver->resolve($targetDocumentId),
			'lastChange' => $this->lastChangeResolver->resolve($targetDocumentId),
			'subscription' => $this->subscriptionStateResolver->resolve($targetDocumentId, $this->getCurrentUserId()),
			'backlinks' => $this->backlinksSnapshotResolver->resolve($targetDocumentId),
			'isFavorite' => $this->isFavorite($targetDocumentId),
		];

		$yjsState = $targetDocument->getYjsState();
		if (
			$targetDocument->getContentFormat() === DocumentTable::CONTENT_FORMAT_YJS
			&& $yjsState !== null
			&& $yjsState !== ''
		)
		{
			$targetDocumentPayload['yjsState'] = $yjsState;
		}
		else
		{
			$targetDocumentPayload['markdown'] = $targetDocument->getMarkdown();
		}

		if ($collabMeta !== null)
		{
			$targetDocumentPayload['collaboration'] = $collabMeta;
		}

		$payload = [
			'selectedCollectionId' => $collectionId,
			'selectedDocId' => $targetDocumentId,
			'collections' => [
				[
					'id' => $collectionId,
					'name' => $normalizedCollectionName,
					'position' => 1,
					'canEditCollection' => $canEditCollection,
				],
			],
			'expandedDocs' => $expandedDocs,
			'branches' => $branches,
			'document' => $targetDocumentPayload,
		];

		if ($shouldExpandSidebarTree)
		{
			$payload['expandedCollections'] = [(string)$collectionId => true];
		}

		return $payload;
	}

	private function buildSharedAccessContext(
		Document $document,
		int $documentId,
		int $collectionId,
		array $snapshot,
	): array
	{
		$canEdit = $snapshot['canEdit'];
		$userId = $this->getCurrentUserId();
		// Same ORM-cached read as the collection path above, so `canEnableCollaboration` is a hint here too.
		$collabMeta = (new CollaborationProvider())->buildCollaborationMeta(
			$documentId,
			$collectionId,
			$userId,
			$canEdit,
			$document->getContentFormat(),
			$document->getMaterializedUptoId(),
		);

		// Breadcrumb for a shared document: the container collection is a label only (no
		// collectionId — the crumb renders as plain text since the workspace is closed to this
		// user), and the ancestor chain stops at the first ancestor they may not see. parentId
		// follows the same rule, so it never points at a hidden document.
		$ancestors = (new AccessibleAncestorResolver($this->documentRepository))
			->resolve($documentId, $collectionId, $userId)
		;
		$parentId = (int)$document->getParentId();
		$visibleParentId = null;
		foreach ($ancestors as $ancestor)
		{
			if ($ancestor->id === $parentId)
			{
				$visibleParentId = $parentId;
				break;
			}
		}

		$archivedAt = $document->getArchivedAt();
		$targetDocumentPayload = [
			'id' => $documentId,
			'canEdit' => $canEdit,
			'canEditCollection' => false,
			'canManagePermissions' => false,
			'sharedAccess' => true,
			'parentId' => $visibleParentId,
			'title' => $document->getTitle(),
			'createdAt' => $document->getCreatedAt()->format('c'),
			'contentFormat' => $document->getContentFormat(),
			'position' => $document->getPosition(),
			'isArchived' => (bool)$document->getIsArchived(),
			'archivedAt' => $archivedAt?->format('c'),
			'isTrashed' => false,
			'hasChildren' =>false,
			'collectionTitle' => $this->resolveCollectionTitle($collectionId),
			// Container id for the accessible-tree namespace (children block / shared branches).
			// Not collectionId: that field is what would make the breadcrumb container clickable.
			'sharedCollectionId' => $collectionId,
			'ancestors' => BreadcrumbAncestor::listToArray($ancestors),
			'views' => $this->viewsSnapshotResolver->resolve($documentId),
			'lastChange' => $this->lastChangeResolver->resolve($documentId),
			'subscription' => $this->subscriptionStateResolver->resolve($documentId, $this->getCurrentUserId()),
			'backlinks' => $this->backlinksSnapshotResolver->resolve($documentId),
			'isFavorite' => $this->isFavorite($documentId),
		];

		$yjsState = $document->getYjsState();
		if (
			$document->getContentFormat() === DocumentTable::CONTENT_FORMAT_YJS
			&& $yjsState !== null
			&& $yjsState !== ''
		)
		{
			$targetDocumentPayload['yjsState'] = $yjsState;
		}
		else
		{
			$targetDocumentPayload['markdown'] = $document->getMarkdown();
		}

		if ($collabMeta !== null)
		{
			$targetDocumentPayload['collaboration'] = $collabMeta;
		}

		return [
			'selectedCollectionId' => null,
			'selectedDocId' => $documentId,
			'collections' => [],
			'expandedCollections' => [],
			'expandedDocs' => [],
			'branches' => [],
			'document' => $targetDocumentPayload,
		];
	}

	private function resolveCollectionTitle(int $collectionId): string
	{
		if ($collectionId <= 0)
		{
			return '';
		}

		$row = CollectionTable::query()
			->setSelect(['NAME'])
			->where('ID', $collectionId)
			->setCacheTtl(60)
			->fetch()
		;

		return $row === false ? '' : trim((string)($row['NAME'] ?? ''));
	}

	private function buildTrashedContext(
		Document $document,
		int $documentId,
		RecycleBinRecord $record,
		bool $canRestore = false,
		bool $canHardDelete = false,
	): array
	{
		$rawCollectionId = (int)$document->getCollectionId();
		$collectionAlive = false;
		$collectionTitle = '';
		if ($rawCollectionId > 0)
		{
			$row = CollectionTable::query()
				->setSelect(['ID', 'NAME'])
				->where('ID', $rawCollectionId)
				->fetch()
			;
			if ($row !== false)
			{
				$collectionAlive = true;
				$collectionTitle = (string)($row['NAME'] ?? '');
			}
		}

		$exposedCollectionId = $collectionAlive ? $rawCollectionId : 0;
		$exposedCollectionTitle = $collectionAlive ? $collectionTitle : '';

		$archivedAt = $document->getArchivedAt();
		$targetDocumentPayload = [
			'id' => $documentId,
			'collectionId' => $exposedCollectionId,
			'collectionTitle' => $exposedCollectionTitle,
			'canEdit' => false,
			'canEditCollection' => false,
			'parentId' => $this->toNullableInt($document->getParentId()),
			'title' => $document->getTitle(),
			'createdAt' => $document->getCreatedAt()->format('c'),
			'contentFormat' => $document->getContentFormat(),
			'position' => $document->getPosition(),
			'isArchived' => (bool)$document->getIsArchived(),
			'archivedAt' => $archivedAt !== null ? $archivedAt->format('c') : null,
			'isTrashed' => true,
			'recycleBinId' => (int)$record->getId(),
			'trashedAt' => $record->getTrashedAt()->format('c'),
			'isOrphan' => !$collectionAlive,
			'canRestore' => $canRestore,
			'canHardDelete' => $canHardDelete,
			'hasChildren' => false,
			'ancestors' => [],
			'views' => $this->viewsSnapshotResolver->resolve($documentId),
			'lastChange' => $this->lastChangeResolver->resolve($documentId),
			'subscription' => $this->subscriptionStateResolver->resolve($documentId, $this->getCurrentUserId()),
			'backlinks' => $this->backlinksSnapshotResolver->resolve($documentId),
			'isFavorite' => $this->isFavorite($documentId),
		];

		$yjsState = $document->getYjsState();
		if (
			$document->getContentFormat() === DocumentTable::CONTENT_FORMAT_YJS
			&& $yjsState !== null
			&& $yjsState !== ''
		)
		{
			$targetDocumentPayload['yjsState'] = $yjsState;
		}
		else
		{
			$targetDocumentPayload['markdown'] = $document->getMarkdown();
		}

		return [
			'selectedCollectionId' => null,
			'selectedDocId' => $documentId,
			'collections' => [],
			'expandedCollections' => [],
			'expandedDocs' => [],
			'branches' => [],
			'document' => $targetDocumentPayload,
		];
	}

	private function toNullableInt($value): ?int
	{
		if ($value === null || $value === '' || $value === false)
		{
			return null;
		}

		return (int)$value;
	}

	/**
	 * [TPL-01] Star state of the opened document, bundled next to the bell state for the same
	 * reason: the header adopts it instead of asking for it on mount. Unknown means not starred.
	 */
	private function isFavorite(int $documentId): bool
	{
		return $this->favoriteRepository->findRow(
			$this->getCurrentUserId(),
			FavoriteTable::ENTITY_TYPE_DOCUMENT,
			$documentId,
		) !== null;
	}

	private function branchKey(int $collectionId, ?int $parentId): string
	{
		return $collectionId . ':' . ($parentId === null ? 'root' : (string)$parentId);
	}

	private function getCurrentUserId(): int
	{
		return (int)CurrentUser::get()->getId();
	}

}
