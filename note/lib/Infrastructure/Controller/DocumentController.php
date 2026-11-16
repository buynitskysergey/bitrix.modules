<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Controller;

use Bitrix\Main\Command\Exception\CommandException;
use Bitrix\Main\Context;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\SystemException;
use Bitrix\Note\Internal\Access\Service\CollectionAccessService;
use Bitrix\Note\Internal\Access\Service\DocumentAccessService;
use Bitrix\Note\Internal\Entity\Document\BreadcrumbAncestor;
use Bitrix\Note\Internal\Exceptions\AccessDeniedException;
use Bitrix\Note\Internal\Exceptions\CollectionNotFoundException;
use Bitrix\Note\Internal\Exceptions\CollectionNotWritableException;
use Bitrix\Note\Internal\Exceptions\DocumentArchivedException;
use Bitrix\Note\Internal\Exceptions\DocumentInRecycleBinException;
use Bitrix\Note\Internal\Exceptions\DocumentNotFoundException;
use Bitrix\Note\Internal\Exceptions\MoveAccessEscalationException;
use Bitrix\Note\Internal\Repository\CollectionRepository;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Repository\DocumentUpdateRepository;
use Bitrix\Note\Internal\Repository\FavoriteRepository;
use Bitrix\Note\Internal\Repository\RecycleBinRepository;
use Bitrix\Note\Internal\Model\Collection;
use Bitrix\Note\Internal\Model\Document;
use Bitrix\Note\Internal\Entity\RecycleBin\RecycleBinRecord;
use Bitrix\Note\Internal\Model\CollectionTable;
use Bitrix\Note\Internal\Model\DocumentTable;
use Bitrix\Note\Internal\Model\FavoriteTable;
use Bitrix\Note\Internal\Service\Document\DocumentBacklinksSnapshotResolver;
use Bitrix\Note\Internal\Service\Document\DocumentLastChangeResolver;
use Bitrix\Note\Internal\Service\Document\DocumentSubscriptionStateResolver;
use Bitrix\Note\Internal\Service\Document\DocumentViewsSnapshotResolver;
use Bitrix\Note\Internal\Service\Document\Position\PositionService;
use Bitrix\Note\Internal\Service\File\DocumentFilesMapService;
use Bitrix\Note\Internal\Service\Bulk\BulkAccessAggregator;
use Bitrix\Note\Internal\Service\Bulk\SelectionResolver;
use Bitrix\Note\Infrastructure\Agent\Document\OwnedMoveContinuationScheduler;
use Bitrix\Note\Internal\Service\Sidebar\AccessibleAncestorResolver;
use Bitrix\Note\Internal\Service\Sidebar\AccessibleTreeService;
use Bitrix\Note\Internal\Service\Sidebar\DirectOpenContextService;
use Bitrix\Note\Public\Command\ArchiveAllInCollectionCommand;
use Bitrix\Note\Public\Command\ArchiveAllOwnedInCollectionCommand;
use Bitrix\Note\Public\Command\ArchiveDocumentCommand;
use Bitrix\Note\Public\Command\BulkArchiveDocumentsCommand;
use Bitrix\Note\Public\Command\BulkDeleteDocumentsCommand;
use Bitrix\Note\Public\Command\BulkMoveDocumentsCommand;
use Bitrix\Note\Public\Command\MoveSelectedDocumentsCommand;
use Bitrix\Note\Public\Command\BulkRestoreDocumentsCommand;
use Bitrix\Note\Public\Command\CreateDocumentCommand;
use Bitrix\Note\Public\Command\DeleteAllArchivedDocumentsCommand;
use Bitrix\Note\Public\Command\DeleteAllInCollectionCommand;
use Bitrix\Note\Public\Command\DeleteAllOwnedInCollectionCommand;
use Bitrix\Note\Public\Command\DeleteDocumentCommand;
use Bitrix\Note\Public\Command\MoveAllInCollectionCommand;
use Bitrix\Note\Public\Command\MoveAllOwnedInCollectionCommand;
use Bitrix\Note\Public\Command\MoveDocumentCommand;
use Bitrix\Note\Public\Command\RestoreAllArchivedDocumentsCommand;
use Bitrix\Note\Public\Command\RestoreDocumentCommand;
use Bitrix\Note\Public\Command\RestoreDocumentVersionCommand;
use Bitrix\Note\Public\Command\UpdateDocumentCommand;
use Bitrix\Note\Public\Provider\BacklinkProvider;
use Bitrix\Note\Public\Provider\CollaborationProvider;
use Bitrix\Note\Public\Provider\CollectionProvider;
use Bitrix\Note\Public\Provider\ContentList\CollectionStateResolver;
use Bitrix\Note\Public\Provider\ContentList\LevelProvider;
use Bitrix\Note\Public\Provider\ContentList\SectionLevelPreloader;
use Bitrix\Note\Public\Provider\ContentList\SectionProvider;
use Bitrix\Note\Public\Provider\DocumentProvider;
use Bitrix\Note\Public\Provider\FeedProvider;
use Bitrix\Note\Public\Provider\Param\Document\DocumentLimits;
use Bitrix\Note\Public\Provider\TreeProvider;
use Bitrix\Note\Public\Provider\VersionProvider;
use Bitrix\Note\Public\Provider\ViewProvider;

class DocumentController extends Controller
{
	use LevelScopeTrait;

	protected function getDefaultPreFilters(): array
	{
		return array_merge(
			parent::getDefaultPreFilters(),
			[
				new ActionFilter\NoteAccess(),
			],
		);
	}

	public function createAction(
		int $collectionId,
		string $title,
		?int $parentId = null,
		string $markdown = ''
	): ?array
	{
		if (!$this->assertCollectionManageAccess($collectionId))
		{
			return null;
		}

		// Byte-precise limit (UTF-8): char-based Length cannot express it, mirrors REST V3 addAction.
		if ($markdown !== '' && strlen($markdown) > DocumentLimits::MAX_MARKDOWN_BYTES)
		{
			$this->addError(new Error(Loc::getMessage('NOTE_DOCUMENT_MARKDOWN_TOO_LARGE'), 'DOCUMENT_MARKDOWN_TOO_LARGE'));

			return null;
		}

		$contentFormat = $markdown !== '' ? DocumentTable::CONTENT_FORMAT_MD : DocumentTable::CONTENT_FORMAT_YJS;

		$userId = (int)$this->getCurrentUser()->getId();
		try
		{
			$result = (new CreateDocumentCommand($collectionId, $parentId, $title, $markdown, $userId, $contentFormat))->run();
		}
		catch (CommandException $e)
		{
			// The state of the base is a server-side ban, so a direct call by id gets a named
			// refusal and not a generic failure.
			if ($e->getPrevious() instanceof CollectionNotWritableException)
			{
				$this->addError(new Error(
					(string)Loc::getMessage('NOTE_COLLECTION_READ_ONLY'),
					'NOTE_COLLECTION_READ_ONLY',
				));

				return null;
			}

			$this->addError(new Error(Loc::getMessage('NOTE_DOCUMENT_CREATE_ERROR')));

			return null;
		}
		catch (CollectionNotWritableException)
		{
			$this->addError(new Error(
				(string)Loc::getMessage('NOTE_COLLECTION_READ_ONLY'),
				'NOTE_COLLECTION_READ_ONLY',
			));

			return null;
		}
		catch (SystemException $e)
		{
			$this->addError(new Error(Loc::getMessage('NOTE_DOCUMENT_CREATE_ERROR')));

			return null;
		}

		$document = $result->getData()['document'] ?? null;
		if (!$document instanceof Document)
		{
			return null;
		}

		return $this->mapDocumentMeta($document);
	}

	public function updateAction(int $id, ?string $title = null): ?array
	{
		$existingDocument = (new DocumentProvider())->getMetaById($id);
		if ($existingDocument === null || !$this->assertDocumentEditAccess($id, (int)$existingDocument->getCollectionId()))
		{
			return null;
		}

		$userId = (int)$this->getCurrentUser()->getId();
		try
		{
			$result = (new UpdateDocumentCommand($id, $title, $userId))->run();
		}
		catch (DocumentInRecycleBinException)
		{
			$this->addError(new Error(Loc::getMessage('NOTE_DOCUMENT_TRASHED'), 'DOCUMENT_TRASHED'));

			return null;
		}
		catch (DocumentArchivedException)
		{
			$this->addError(new Error(Loc::getMessage('NOTE_DOCUMENT_ARCHIVED')));

			return null;
		}
		catch (SystemException $e)
		{
			$this->addError(new Error(Loc::getMessage('NOTE_DOCUMENT_UPDATE_ERROR')));

			return null;
		}
		$document = $result->getData()['document'] ?? null;
		if (!$document instanceof Document)
		{
			return null;
		}

		return $this->mapDocumentMeta($document);
	}

	public function moveAction(int $id, int $collectionId, ?int $parentId = null, ?int $position = null): ?array
	{
		$existingDocument = (new DocumentProvider())->getMetaById($id);
		if ($existingDocument === null)
		{
			return null;
		}

		if (
			!$this->assertCollectionManageAccess($existingDocument->getCollectionId())
			|| !$this->assertCollectionManageAccess($collectionId)
		)
		{
			return null;
		}

		$userId = (int)$this->getCurrentUser()->getId();
		try
		{
			$result = (new MoveDocumentCommand($id, $collectionId, $parentId, $position, $userId))->run();
		}
		catch (CommandException $e)
		{
			// AbstractCommand::run() wraps every exception thrown from execute() into a CommandException,
			// so the domain-typed exceptions arrive as its previous — unwrap to keep their typed handling.
			// Without this the escalation code never reaches the frontend (it degrades to a generic error).
			$cause = $e->getPrevious();
			if ($cause instanceof DocumentInRecycleBinException)
			{
				$this->addError(new Error(Loc::getMessage('NOTE_DOCUMENT_TRASHED'), 'DOCUMENT_TRASHED'));

				return null;
			}
			if ($cause instanceof DocumentArchivedException)
			{
				$this->addError(new Error(Loc::getMessage('NOTE_DOCUMENT_ARCHIVED')));

				return null;
			}
			if ($cause instanceof CollectionNotWritableException)
			{
				// The receiving base is read-only in its state.
				$this->addError(new Error(
					(string)Loc::getMessage('NOTE_COLLECTION_READ_ONLY'),
					'NOTE_COLLECTION_READ_ONLY',
				));

				return null;
			}
			if ($cause instanceof MoveAccessEscalationException)
			{
				$this->addError(new Error(
					Loc::getMessage('NOTE_DOCUMENT_MOVE_ACCESS_ESCALATION'),
					PositionService::ERROR_MOVE_ACCESS_ESCALATION,
				));

				return null;
			}

			$this->addError(new Error(Loc::getMessage('NOTE_DOCUMENT_MOVE_ERROR')));

			return null;
		}
		catch (SystemException $e)
		{
			$this->addError(new Error(Loc::getMessage('NOTE_DOCUMENT_MOVE_ERROR')));

			return null;
		}
		$data = $result->getData();
		$document = $data['document'] ?? null;
		if (!$document instanceof Document)
		{
			return null;
		}

		$documentId = (int)$document->getId();
		// MoveDocumentCommand performed the single full read needed for the title — reuse it
		// here instead of issuing another getById.
		$fullDocument = $data['fullDocument'] ?? null;
		if (!$fullDocument instanceof Document)
		{
			return null;
		}

		$collectionIdOut = (int)$fullDocument->getCollectionId();
		$childrenCountMap = (new DocumentRepository())->getChildrenCountMapByParentIds(
			$collectionIdOut,
			[$documentId],
		);

		return [
			'id' => $documentId,
			'collectionId' => $collectionIdOut,
			'parentId' => $fullDocument->getParentId(),
			'position' => $fullDocument->getPosition(),
			'title' => $fullDocument->getTitle(),
			'hasChildren' => isset($childrenCountMap[$documentId]),
			'affectedPositions' => $data['affectedPositions'] ?? [],
		];
	}

	public function archiveAction(int $id, bool $withNested = true): ?array
	{
		$ownership = (new DocumentProvider())->getOwnershipInfo($id);
		if ($ownership === null || !$this->assertCollectionManageAccess($ownership['collectionId']))
		{
			return null;
		}

		$userId = (int)$this->getCurrentUser()->getId();
		try
		{
			(new ArchiveDocumentCommand($id, $userId, withNested: $withNested))->run();
		}
		catch (SystemException $e)
		{
			$this->addError(new Error(Loc::getMessage('NOTE_DOCUMENT_ARCHIVE_ERROR')));

			return null;
		}

		return ['id' => $id];
	}

	public function restoreAction(int $id): ?array
	{
		$ownership = (new DocumentProvider())->getOwnershipInfo($id);
		if ($ownership === null || !$this->assertCollectionManageAccess($ownership['collectionId']))
		{
			return null;
		}

		$userId = (int)$this->getCurrentUser()->getId();
		try
		{
			$result = (new RestoreDocumentCommand($id, $userId))->run();
		}
		catch (DocumentNotFoundException)
		{
			$this->addError(new Error(Loc::getMessage('NOTE_DOCUMENT_RESTORE_ERROR')));

			return null;
		}
		catch (SystemException $e)
		{
			$this->addError(new Error(Loc::getMessage('NOTE_DOCUMENT_RESTORE_ERROR')));

			return null;
		}

		$document = $result->getData()['document'] ?? null;
		if (!$document instanceof Document)
		{
			return null;
		}

		$response = $this->mapDocumentMeta($document);
		$restoredCollection = $result->getData()['restoredCollection'] ?? null;
		if ($restoredCollection instanceof Collection)
		{
			$response['restoredCollection'] = (new CollectionProvider())
				->mapCollectionForCurrentUser($restoredCollection)
			;
		}

		return $response;
	}

	public function restoreAllAction(): ?array
	{
		$userId = (int)$this->getCurrentUser()->getId();
		if ($userId <= 0)
		{
			return null;
		}

		try
		{
			$result = (new RestoreAllArchivedDocumentsCommand($userId))->run();
		}
		catch (SystemException $e)
		{
			$this->addError(new Error(Loc::getMessage('NOTE_DOCUMENT_RESTORE_ALL_ERROR')));

			return null;
		}

		$restoredCollections = $result->getData()['restoredCollections'] ?? [];
		$collectionProvider = new CollectionProvider();
		$mappedCollections = [];
		foreach ($restoredCollections as $restoredCollection)
		{
			if ($restoredCollection instanceof Collection)
			{
				$mappedCollections[] = $collectionProvider->mapCollectionForCurrentUser($restoredCollection);
			}
		}

		return [
			'restoredCount' => (int)($result->getData()['restoredCount'] ?? 0),
			'restoredCollections' => $mappedCollections,
		];
	}

	public function deleteAllArchivedAction(): ?array
	{
		$userId = (int)$this->getCurrentUser()->getId();
		if ($userId <= 0)
		{
			return null;
		}

		try
		{
			$result = (new DeleteAllArchivedDocumentsCommand($userId))->run();
		}
		catch (SystemException)
		{
			$this->addError(new Error(Loc::getMessage('NOTE_DOCUMENT_DELETE_ALL_ARCHIVED_ERROR')));

			return null;
		}

		return [
			'deletedCount' => (int)($result->getData()['deletedCount'] ?? 0),
		];
	}

	/**
	 * Read-only pre-count of the documents a bulk
	 * action would touch. Runs only the selection resolver (no access check, no mutation,
	 * no side effects) so the frontend can confirm the volume before acting.
	 *
	 * @param int[] $documentIds
	 */
	public function resolveBulkSelectionAction(array $documentIds, string $section, bool $withNested = false): ?array
	{
		$userId = (int)$this->getCurrentUser()->getId();
		if ($userId <= 0)
		{
			return null;
		}

		// Validate the section against the same whitelist the mutating bulk actions enforce
		// (plus recycle, which this read-only pre-count also serves via canRestore).
		if (!in_array(
			$section,
			[SelectionResolver::SECTION_ACTIVE, SelectionResolver::SECTION_ARCHIVE, SelectionResolver::SECTION_RECYCLE],
			true,
		))
		{
			$this->addError(new Error(Loc::getMessage('NOTE_BULK_INVALID_SECTION'), 'NOTE_BULK_INVALID_SECTION'));

			return null;
		}

		$rootIds = array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $documentIds),
			static fn(int $id): bool => $id > 0,
		)));
		if (empty($rootIds))
		{
			$this->addError(new Error(Loc::getMessage('NOTE_BULK_EMPTY_SELECTION'), 'NOTE_BULK_EMPTY_SELECTION'));

			return null;
		}

		$accessCodes = CollectionAccessService::buildUserAccessCodes($userId);
		$resolver = new SelectionResolver();
		$aggregator = new BulkAccessAggregator();

		// First narrow the selected roots to those the caller may actually act on (MANAGE on the
		// owning collection, or canRestore in the recycle bin). Roots-only resolve gives the
		// collection grouping the aggregator needs, without expanding subtrees yet.
		$rootResolution = $resolver->resolve($rootIds, $section, false);

		// The root count alone can exceed the cap (e.g. "select all loaded" over an infinite scroll).
		// The resolver already flags it with an empty expansion; report the cap straight away rather
		// than falling through to the empty-access branch, which would mislabel it as "0 / not over
		// limit". The count reflects what the caller itself submitted, so it leaks nothing about
		// foreign ids; hasNested stays false (no expansion was performed).
		if ($rootResolution['limitExceeded'])
		{
			return [
				'affectedCount' => 0,
				'limitExceeded' => true,
				'hasNested' => false,
			];
		}

		$accessibleRootIds = $aggregator->aggregate(
			$rootResolution['ids'],
			$rootResolution['byCollection'],
			$section,
			CollectionAccessService::LEVEL_MANAGE,
			$userId,
			$accessCodes,
			BulkAccessAggregator::RECYCLE_CAP_RESTORE,
		)['allowedIds'];

		// Nothing accessible: there is no subtree to expand and no cap to report, so the
		// structural flags stay false — the endpoint cannot be used to probe the existence,
		// child-presence or subtree size of foreign ids.
		if (empty($accessibleRootIds))
		{
			return [
				'affectedCount' => 0,
				'limitExceeded' => false,
				'hasNested' => false,
			];
		}

		// Compute the real pre-count over the accessible roots only. limitExceeded / hasNested
		// now describe exactly the caller's own accessible slice: an over-limit accessible
		// selection reports limitExceeded=true (with an empty expansion, per the resolver
		// contract), and neither flag exposes anything about inaccessible ids.
		$resolution = $resolver->resolve($accessibleRootIds, $section, $withNested);
		$access = $aggregator->aggregate(
			$resolution['ids'],
			$resolution['byCollection'],
			$section,
			CollectionAccessService::LEVEL_MANAGE,
			$userId,
			$accessCodes,
			BulkAccessAggregator::RECYCLE_CAP_RESTORE,
		);

		return [
			'affectedCount' => count($access['allowedIds']),
			'limitExceeded' => (bool)$resolution['limitExceeded'],
			'hasNested' => (bool)$resolution['hasNested'],
		];
	}

	/**
	 * Bulk archive of a tree selection (active section).
	 * Per-document MANAGE is decided inside the command; undeletable rows land in
	 * skippedByAccessCount, they are not an endpoint error.
	 *
	 * @param int[] $documentIds
	 */
	public function archiveManyAction(array $documentIds, bool $withNested = true): ?array
	{
		$userId = (int)$this->getCurrentUser()->getId();
		if ($userId <= 0)
		{
			return null;
		}

		$rootIds = $this->normalizeDocumentIds($documentIds);
		if (empty($rootIds))
		{
			$this->addError(new Error(Loc::getMessage('NOTE_BULK_EMPTY_SELECTION'), 'NOTE_BULK_EMPTY_SELECTION'));

			return null;
		}

		return $this->runBulkCommand(new BulkArchiveDocumentsCommand($userId, $rootIds, $withNested));
	}

	/**
	 * Bulk soft-delete of a tree selection from the
	 * active or archive section.
	 *
	 * @param int[] $documentIds
	 */
	public function deleteManyAction(array $documentIds, string $section, bool $withNested = true): ?array
	{
		$userId = (int)$this->getCurrentUser()->getId();
		if ($userId <= 0)
		{
			return null;
		}

		if (!in_array($section, [SelectionResolver::SECTION_ACTIVE, SelectionResolver::SECTION_ARCHIVE], true))
		{
			$this->addError(new Error(Loc::getMessage('NOTE_BULK_INVALID_SECTION'), 'NOTE_BULK_INVALID_SECTION'));

			return null;
		}

		$rootIds = $this->normalizeDocumentIds($documentIds);
		if (empty($rootIds))
		{
			$this->addError(new Error(Loc::getMessage('NOTE_BULK_EMPTY_SELECTION'), 'NOTE_BULK_EMPTY_SELECTION'));

			return null;
		}

		return $this->runBulkCommand(new BulkDeleteDocumentsCommand($userId, $rootIds, $section, $withNested));
	}

	/**
	 * Bulk restore of a tree selection from the archive
	 * section. Per-document MANAGE is decided inside the command; documents the user cannot
	 * manage land in skippedByAccessCount, they are not an endpoint error. Jointly selected
	 * parent/child pairs are restored top-down so the child re-attaches under its parent.
	 *
	 * The branch is what the interface offers, but withNested defaults to off all the same: the
	 * parameter was added to an action that already existed, and a caller from before it has to keep
	 * getting what it used to ask for. The client of this surface always sends the flag.
	 *
	 * @param int[] $documentIds
	 */
	public function restoreManyAction(array $documentIds, bool $withNested = false): ?array
	{
		$userId = (int)$this->getCurrentUser()->getId();
		if ($userId <= 0)
		{
			return null;
		}

		$rootIds = $this->normalizeDocumentIds($documentIds);
		if (empty($rootIds))
		{
			$this->addError(new Error(Loc::getMessage('NOTE_BULK_EMPTY_SELECTION'), 'NOTE_BULK_EMPTY_SELECTION'));

			return null;
		}

		// A card in the archive stands for its branch, exactly as it did when it was
		// archived; the composition is expanded on the server, the client does not hold it.
		return $this->runBulkCommand(
			new BulkRestoreDocumentsCommand(
				$userId,
				$rootIds,
				SelectionResolver::SECTION_ARCHIVE,
				null,
				$withNested,
			),
		);
	}

	/**
	 * Bulk move of selected roots (each with its subtree)
	 * into a target collection/parent. Requires MANAGE on the target collection (checked here)
	 * AND on every source document's collection (checked per document inside the command).
	 *
	 * Roots the user cannot manage, and roots whose target falls inside their own subtree, land
	 * in the outcome as skipped (partial success). Only when every root's target sits inside its
	 * own subtree is the request wholly unsatisfiable — that becomes NOTE_INVALID_TARGET.
	 *
	 * $selectedOnly picks the form of the move, not the right to make it: with it only the selected
	 * documents travel and everything else stays in the source collection. It is not about authorship
	 * and not tied to any one tab — "My articles" always sends it, "All documents" sends it whenever
	 * the "with nested" toggle is off. A target inside the selection makes the whole request
	 * unsatisfiable and is reported as NOTE_INVALID_TARGET.
	 *
	 * @param int[] $documentIds
	 */
	public function moveManyAction(
		array $documentIds,
		int $targetCollectionId,
		?int $targetParentId = null,
		bool $selectedOnly = false,
	): ?array
	{
		$userId = (int)$this->getCurrentUser()->getId();
		if ($userId <= 0)
		{
			return null;
		}

		$rootIds = $this->normalizeDocumentIds($documentIds);
		if (empty($rootIds))
		{
			$this->addError(new Error(Loc::getMessage('NOTE_BULK_EMPTY_SELECTION'), 'NOTE_BULK_EMPTY_SELECTION'));

			return null;
		}

		if (!$this->hasCollectionLevel($targetCollectionId, CollectionAccessService::LEVEL_MANAGE))
		{
			$this->addError(new Error(Loc::getMessage('NOTE_ACCESS_DENIED'), 'NOTE_ACCESS_DENIED'));

			return null;
		}

		$normalizedParentId = $targetParentId !== null && $targetParentId > 0 ? $targetParentId : null;
		if ($normalizedParentId !== null)
		{
			// A target parent must be a live document of the target collection (or be the root,
			// i.e. null); anything else is an invalid destination.
			$targetParent = (new DocumentProvider())->getMetaById($normalizedParentId);
			if ($targetParent === null || (int)$targetParent->getCollectionId() !== $targetCollectionId)
			{
				$this->addError(new Error(Loc::getMessage('NOTE_INVALID_TARGET'), 'NOTE_INVALID_TARGET'));

				return null;
			}
		}

		try
		{
			$command = $selectedOnly
				? new MoveSelectedDocumentsCommand($userId, $rootIds, $targetCollectionId, $normalizedParentId)
				: new BulkMoveDocumentsCommand($userId, $rootIds, $targetCollectionId, $normalizedParentId)
			;
			$data = $command->run()->getData();
		}
		catch (SystemException)
		{
			$this->addError(new Error(Loc::getMessage('NOTE_BULK_ERROR'), 'NOTE_BULK_ERROR'));

			return null;
		}

		$outcome = $data['outcome'] ?? null;
		if (!is_array($outcome))
		{
			$this->addError(new Error(Loc::getMessage('NOTE_BULK_ERROR'), 'NOTE_BULK_ERROR'));

			return null;
		}

		if (($outcome['limitExceeded'] ?? false) === true)
		{
			$this->addError(new Error(Loc::getMessage('NOTE_BULK_LIMIT_EXCEEDED'), 'NOTE_BULK_LIMIT_EXCEEDED'));

			return null;
		}

		if (($data['invalidTargetForAll'] ?? false) === true)
		{
			$this->addError(new Error(Loc::getMessage('NOTE_INVALID_TARGET'), 'NOTE_INVALID_TARGET'));

			return null;
		}

		return ['outcome' => $outcome];
	}

	/**
	 * "Select all": archive every document of the
	 * active collection. The count is taken server-side over the whole collection structure.
	 */
	public function archiveAllInCollectionAction(int $collectionId): ?array
	{
		return $this->runAllInCollection($collectionId, true);
	}

	/**
	 * "Select all": soft-delete every document of the
	 * active collection. The count is taken server-side over the whole collection structure.
	 */
	public function deleteAllInCollectionAction(int $collectionId): ?array
	{
		return $this->runAllInCollection($collectionId, false);
	}

	private function runAllInCollection(int $collectionId, bool $archive): ?array
	{
		$userId = (int)$this->getCurrentUser()->getId();
		if ($userId <= 0)
		{
			return null;
		}

		if ((new CollectionRepository())->getById($collectionId) === null)
		{
			$this->addError(new Error(
				(string)Loc::getMessage('NOTE_COLLECTION_NOT_FOUND'),
				'NOTE_COLLECTION_NOT_FOUND',
			));

			return null;
		}

		// The documents of a deleted base are already in the bin; archiving them from here would
		// change what a restore brings back.
		if (!DocumentAccessService::isCollectionAlive($collectionId))
		{
			$this->addError(new Error(
				(string)Loc::getMessage('NOTE_COLLECTION_IN_RECYCLE_BIN'),
				'NOTE_COLLECTION_IN_RECYCLE_BIN',
			));

			return null;
		}

		if (!$this->assertCollectionManageAccess($collectionId))
		{
			return null;
		}

		$command = $archive
			? new ArchiveAllInCollectionCommand($collectionId, $userId)
			: new DeleteAllInCollectionCommand($collectionId, $userId)
		;

		return $this->runBulkCommand($command);
	}

	/**
	 * "Select all" on the "My articles" tab: archive every live document of the collection the
	 * calling user authored. The set is resolved on the server over the whole base, the rows the
	 * client happened to load do not take part in it.
	 */
	public function archiveAllOwnedInCollectionAction(int $collectionId): ?array
	{
		return $this->runAllOwnedInCollection($collectionId, true);
	}

	/**
	 * "Select all" on the "My articles" tab: soft-delete every live document of the collection the
	 * calling user authored.
	 */
	public function deleteAllOwnedInCollectionAction(int $collectionId): ?array
	{
		return $this->runAllOwnedInCollection($collectionId, false);
	}

	/**
	 * VIEW, not MANAGE: the tab belongs to everyone who sees the base, and whether a document may
	 * actually be archived or deleted is decided per document inside the command. A user without
	 * MANAGE gets an outcome with zeroes and a skippedByAccessCount — the partial success the whole
	 * bulk pipeline answers with, not NOTE_ACCESS_DENIED.
	 */
	private function runAllOwnedInCollection(int $collectionId, bool $archive): ?array
	{
		$userId = (int)$this->getCurrentUser()->getId();
		if ($userId <= 0)
		{
			return null;
		}

		if (!$this->collectionExists($collectionId))
		{
			$this->addError(new Error(
				(string)Loc::getMessage('NOTE_COLLECTION_NOT_FOUND'),
				'NOTE_COLLECTION_NOT_FOUND',
			));

			return null;
		}

		if (!$this->assertCollectionViewAccess($collectionId))
		{
			return null;
		}

		$command = $archive
			? new ArchiveAllOwnedInCollectionCommand($collectionId, $userId)
			: new DeleteAllOwnedInCollectionCommand($collectionId, $userId)
		;

		return $this->runBulkCommand($command);
	}

	/**
	 * How many documents "archive/delete all my articles" would touch in this base — the number the
	 * confirmation dialog prints. VIEW is the threshold: the count answers whether the set is
	 * visible, not whether the operation over it would go through.
	 *
	 * The shape is narrower than resolveBulkSelection's on purpose: a server-resolved set has no cap
	 * to exceed and no "with nested" toggle to describe.
	 */
	public function countOwnedInCollectionAction(int $collectionId): ?array
	{
		$userId = (int)$this->getCurrentUser()->getId();
		if ($userId <= 0)
		{
			return null;
		}

		if (!$this->assertCollectionViewAccess($collectionId))
		{
			return null;
		}

		return [
			'affectedCount' => (new DocumentRepository())->countLiveOwnedCollectionDocuments($collectionId, $userId),
		];
	}

	/**
	 * "Move everything to another base": the whole knowledge base — its archive and its recycle bin
	 * included — goes under one parent of the target base. The collection entity itself stays where
	 * it is; only its documents travel.
	 */
	public function moveAllInCollectionAction(
		int $collectionId,
		int $targetCollectionId,
		?int $targetParentId = null,
	): ?array
	{
		$userId = (int)$this->getCurrentUser()->getId();
		if ($userId <= 0)
		{
			return null;
		}

		if (!$this->assertMoveAllSource($collectionId, CollectionAccessService::LEVEL_MANAGE))
		{
			return null;
		}

		if ($targetCollectionId === $collectionId)
		{
			// The set would be the content of its own destination. Only here: the author-scoped move
			// below takes "lift all my articles to the root of this base" as a legitimate request.
			$this->addError(new Error(Loc::getMessage('NOTE_INVALID_TARGET'), 'NOTE_INVALID_TARGET'));

			return null;
		}

		$normalizedParentId = $this->normalizeMoveTarget($targetCollectionId, $targetParentId);
		if ($normalizedParentId === false)
		{
			return null;
		}

		return $this->runMoveAll(
			new MoveAllInCollectionCommand($collectionId, $targetCollectionId, $normalizedParentId, $userId),
			false,
		);
	}

	/**
	 * "Move all my articles to another base". VIEW on the source, for the same reason the rest of the
	 * tab asks for VIEW: what may actually be moved is decided per document inside the command. The
	 * target base may be the source one — lifting one's own articles to its root is a move like any
	 * other.
	 */
	public function moveAllOwnedInCollectionAction(
		int $collectionId,
		int $targetCollectionId,
		?int $targetParentId = null,
	): ?array
	{
		$userId = (int)$this->getCurrentUser()->getId();
		if ($userId <= 0)
		{
			return null;
		}

		if (!$this->assertMoveAllSource($collectionId, CollectionAccessService::LEVEL_VIEW))
		{
			return null;
		}

		$normalizedParentId = $this->normalizeMoveTarget($targetCollectionId, $targetParentId);
		if ($normalizedParentId === false)
		{
			return null;
		}

		return $this->runMoveAll(
			new MoveAllOwnedInCollectionCommand(
				$collectionId,
				$targetCollectionId,
				$normalizedParentId,
				$userId,
				deferralSink: new OwnedMoveContinuationScheduler(),
			),
			true,
		);
	}

	/**
	 * How many documents the base holds — the number the confirmation of "move the whole base" prints.
	 * Live ones: the archive and the recycle bin travel too, but the figure answers for what the user
	 * sees and works with, and the text of the confirmation speaks for the rest.
	 */
	public function countInCollectionAction(int $collectionId): ?array
	{
		$userId = (int)$this->getCurrentUser()->getId();
		if ($userId <= 0)
		{
			return null;
		}

		if (!$this->assertCollectionViewAccess($collectionId))
		{
			return null;
		}

		return ['affectedCount' => (new DocumentRepository())->countLiveInCollection($collectionId)];
	}

	private function assertMoveAllSource(int $collectionId, int $requiredLevel): bool
	{
		if (!$this->collectionExists($collectionId))
		{
			$this->addError(new Error(
				(string)Loc::getMessage('NOTE_COLLECTION_NOT_FOUND'),
				'NOTE_COLLECTION_NOT_FOUND',
			));

			return false;
		}

		$granted = $requiredLevel === CollectionAccessService::LEVEL_MANAGE
			? $this->assertCollectionManageAccess($collectionId)
			: $this->assertCollectionViewAccess($collectionId)
		;

		// Carrying documents away is a write into the source base, so an archived one is refused with
		// the same code an archived target is. The commands keep their own guard for other callers.
		return $granted && $this->assertCollectionWritable($collectionId);
	}

	private function assertCollectionWritable(int $collectionId): bool
	{
		try
		{
			CollectionAccessService::assertCollectionAcceptsWrites($collectionId);
		}
		catch (CollectionNotWritableException)
		{
			$this->addError(new Error(
				(string)Loc::getMessage('NOTE_COLLECTION_READ_ONLY'),
				'NOTE_COLLECTION_READ_ONLY',
			));

			return false;
		}

		return true;
	}

	/**
	 * The receiving end of a set move: a base that accepts writes, MANAGE on it, and a target parent
	 * that is a document of that very base.
	 *
	 * @return int|null|false the normalized parent id, or false when the request was already refused
	 */
	private function normalizeMoveTarget(int $targetCollectionId, ?int $targetParentId): int|null|false
	{
		if (!$this->assertCollectionWritable($targetCollectionId))
		{
			return false;
		}

		if (!$this->hasCollectionLevel($targetCollectionId, CollectionAccessService::LEVEL_MANAGE))
		{
			$this->addError(new Error(Loc::getMessage('NOTE_ACCESS_DENIED'), 'NOTE_ACCESS_DENIED'));

			return false;
		}

		$normalizedParentId = $targetParentId !== null && $targetParentId > 0 ? $targetParentId : null;
		if ($normalizedParentId !== null)
		{
			$targetParent = (new DocumentProvider())->getMetaById($normalizedParentId);
			if ($targetParent === null || (int)$targetParent->getCollectionId() !== $targetCollectionId)
			{
				$this->addError(new Error(Loc::getMessage('NOTE_INVALID_TARGET'), 'NOTE_INVALID_TARGET'));

				return false;
			}
		}

		return $normalizedParentId;
	}

	/**
	 * Past runBulkCommand() on purpose: it reads nothing but ['outcome'] out of the command, and a set
	 * move answers with more — the errors of its own guards and the flag that says a tail went to the
	 * background.
	 */
	private function runMoveAll(\Bitrix\Main\Command\AbstractCommand $command, bool $withDeferredTail): ?array
	{
		try
		{
			$result = $command->run();
		}
		catch (SystemException)
		{
			$this->addError(new Error(Loc::getMessage('NOTE_BULK_ERROR'), 'NOTE_BULK_ERROR'));

			return null;
		}

		if (!$result->isSuccess())
		{
			foreach ($result->getErrors() as $error)
			{
				$this->addError($error);
			}

			return null;
		}

		$data = $result->getData();
		$outcome = $data['outcome'] ?? null;
		if (!is_array($outcome))
		{
			$this->addError(new Error(Loc::getMessage('NOTE_BULK_ERROR'), 'NOTE_BULK_ERROR'));

			return null;
		}

		if (($outcome['limitExceeded'] ?? false) === true)
		{
			$this->addError(new Error(Loc::getMessage('NOTE_BULK_LIMIT_EXCEEDED'), 'NOTE_BULK_LIMIT_EXCEEDED'));

			return null;
		}

		if (($data['invalidTargetForAll'] ?? false) === true)
		{
			$this->addError(new Error(Loc::getMessage('NOTE_INVALID_TARGET'), 'NOTE_INVALID_TARGET'));

			return null;
		}

		return $withDeferredTail
			? ['outcome' => $outcome, 'deferredTail' => (bool)($data['deferredTail'] ?? false)]
			: ['outcome' => $outcome]
		;
	}

	/**
	 * Runs a bulk command and normalizes its BulkOutcome (DTO-01) into the wire response.
	 * A resolved selection over the cap is surfaced as NOTE_BULK_LIMIT_EXCEEDED, not a
	 * zero-count success.
	 */
	private function runBulkCommand(\Bitrix\Main\Command\AbstractCommand $command): ?array
	{
		try
		{
			$outcome = $command->run()->getData()['outcome'] ?? null;
		}
		catch (SystemException)
		{
			$this->addError(new Error(Loc::getMessage('NOTE_BULK_ERROR'), 'NOTE_BULK_ERROR'));

			return null;
		}

		if (!is_array($outcome))
		{
			$this->addError(new Error(Loc::getMessage('NOTE_BULK_ERROR'), 'NOTE_BULK_ERROR'));

			return null;
		}

		if (($outcome['limitExceeded'] ?? false) === true)
		{
			$this->addError(new Error(Loc::getMessage('NOTE_BULK_LIMIT_EXCEEDED'), 'NOTE_BULK_LIMIT_EXCEEDED'));

			return null;
		}

		return ['outcome' => $outcome];
	}

	/**
	 * @param int[] $documentIds
	 * @return int[]
	 */
	private function normalizeDocumentIds(array $documentIds): array
	{
		return array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $documentIds),
			static fn(int $id): bool => $id > 0,
		)));
	}

	public function listArchivedAction(int $limit = 50, ?array $afterCursor = null): ?array
	{
		$userId = (int)$this->getCurrentUser()->getId();
		if ($userId <= 0)
		{
			return null;
		}

		$limit = max(1, min(200, $limit));
		$accessCodes = CollectionAccessService::buildUserAccessCodes($userId);

		return (new DocumentProvider())->getArchivedForUser($accessCodes, $userId, $limit, $afterCursor);
	}

	/**
	 * Knowledge bases that hold anything in the archive, with their counters. Alphabetical:
	 * freshness is not a task on this surface. The list of bases is paged like everything else here -
	 * the answer names the base it stopped at, and the next page is asked for from there.
	 *
	 * @param array{title: string, collectionId: int}|null $cursor
	 */
	public function archiveSectionsAction(?array $cursor = null): ?array
	{
		$userId = (int)$this->getCurrentUser()->getId();
		if ($userId <= 0)
		{
			return null;
		}

		$payload = (new SectionProvider())->getArchiveSections($userId, $cursor);
		$payload['levels'] = SectionLevelPreloader::forArchive($userId, $payload['sections']);

		return $payload;
	}

	/**
	 * One level of the archive: the top level of a section, or the archived children of one
	 * archived node.
	 *
	 * @param array{changedAt: string, id: int}|null $cursor
	 */
	public function archiveLevelAction(
		?int $collectionId = null,
		?int $parentId = null,
		?array $cursor = null,
		int $limit = LevelProvider::DEFAULT_LIMIT,
	): ?array
	{
		$userId = (int)$this->getCurrentUser()->getId();
		if ($userId <= 0)
		{
			return null;
		}

		$scopeCollectionId = $this->resolveLevelScopeCollectionId($collectionId, $parentId);
		if (
			$scopeCollectionId === self::LEVEL_SCOPE_CONFLICT
			|| !(
				$this->userCanViewCollection($userId, $scopeCollectionId)
				|| $this->userReachesArchiveByGrant($userId, $scopeCollectionId, $parentId)
			)
		)
		{
			$this->addError(new Error((string)Loc::getMessage('NOTE_ACCESS_DENIED')));

			return null;
		}

		return (new LevelProvider())->getArchiveLevel($userId, $scopeCollectionId, $parentId, $cursor, $limit);
	}

	/**
	 * The top level of several archive sections at once - the sections the reader scrolled to, past
	 * the ones whose content arrived with the list. Sections come up open, so asking for them one by
	 * one is one request per section, each with a bootstrap of the core of its own.
	 *
	 * The answer carries `levels` in the shape the section list carries it, so the client reads both
	 * with the same parser. A section it does not name is one there was nothing to answer for - see
	 * {@see SectionLevelPreloader::forArchiveCollections()} - and is asked for the usual way.
	 *
	 * @param int[] $collectionIds no more than SectionLevelPreloader::MAX_BATCH_COLLECTIONS answer
	 * @return array{levels: array<string, array{items: array, nextCursor: ?array}>}|null
	 */
	public function archiveLevelsAction(array $collectionIds, int $limit = LevelProvider::DEFAULT_LIMIT): ?array
	{
		$userId = (int)$this->getCurrentUser()->getId();
		if ($userId <= 0)
		{
			return null;
		}

		return ['levels' => SectionLevelPreloader::forArchiveCollections($userId, $collectionIds, $limit)];
	}

	/**
	 * One level of the live tree, in the shape the bin and the archive answer with: the rule
	 * of the tree is one for every list, the live one included. The order here is POSITION and not a
	 * date - in a live tree the order is the user's, set by dragging.
	 *
	 * @param array{position: int, id: int}|null $cursor
	 */
	public function levelAction(
		?int $parentId = null,
		?int $collectionId = null,
		?array $cursor = null,
		int $limit = LevelProvider::DEFAULT_LIMIT,
	): ?array
	{
		$userId = (int)$this->getCurrentUser()->getId();
		if ($userId <= 0)
		{
			return null;
		}

		// Inside a node the caller knows only the node: the knowledge base is read off the parent.
		$scopeCollectionId = $this->resolveLevelScopeCollectionId($collectionId, $parentId);
		if (
			$scopeCollectionId === self::LEVEL_SCOPE_CONFLICT
			|| !$this->userCanViewCollection($userId, $scopeCollectionId)
		)
		{
			$this->addError(new Error((string)Loc::getMessage('NOTE_ACCESS_DENIED')));

			return null;
		}

		return (new LevelProvider())->getLiveLevel($userId, $scopeCollectionId, $parentId, $cursor, $limit);
	}

	/**
	 * The other way into the archive of a knowledge base: a grant on something inside it. Archiving a
	 * document does not take away the access somebody was given to it, so their archive holds such
	 * documents as a section of their own base.
	 *
	 * The top level of that section is read by the grant itself, so asking for it is safe on its own -
	 * the answer is empty when there is nothing granted. A node, on the other hand, is read by its id
	 * alone, so the node itself has to be one the reader was granted.
	 */
	private function userReachesArchiveByGrant(int $userId, int $collectionId, ?int $parentId): bool
	{
		if ($collectionId <= 0 || $userId <= 0)
		{
			return false;
		}

		$codesPersonal = DocumentAccessService::personalCodes(
			CollectionAccessService::buildUserAccessCodes($userId),
		);
		if (empty($codesPersonal))
		{
			return false;
		}

		if ($parentId === null || $parentId <= 0)
		{
			return DocumentAccessService::userHasAnyGrant($codesPersonal);
		}

		return DocumentAccessService::filterGrantedDocumentIds([$parentId], $codesPersonal) !== [];
	}

	/**
	 * A base in the bin answers by the rule of the bin: its content is open to whoever may manage it
	 * there and to nobody else, so a level left in its ACL is not a way in. A base that has no row at
	 * all - the technical section, id 0 - is answered by the level as before.
	 */
	private function userCanViewCollection(int $userId, int $collectionId): bool
	{
		$state = DocumentAccessService::collectionState($collectionId);
		if ($state !== null && $state['trashed'])
		{
			return DocumentAccessService::canManageCollectionInRecycleBin($userId, $collectionId);
		}

		return CollectionAccessService::userHasLevel(
			$collectionId,
			$userId,
			CollectionAccessService::LEVEL_VIEW,
		);
	}

	public function deleteAction(int $id, bool $withNested = true): ?bool
	{
		// A collection's main document is deleted only via the owning collection's cascade,
		// never as a standalone document. Report it as "not found" so no transport can drop it
		// on its own (DeleteDocumentCommand enforces the same invariant one layer deeper).
		if ($id > 0 && (new DocumentRepository())->isMainDocument($id))
		{
			return null;
		}

		$ownership = (new DocumentProvider())->getOwnershipInfo($id);
		if ($ownership === null || !$this->assertCollectionManageAccess($ownership['collectionId']))
		{
			return null;
		}

		$result = (new DeleteDocumentCommand(
			$id,
			(int)$this->getCurrentUser()->getId(),
			withNested: $withNested,
		))->run();

		return (bool)($result->getData()['success'] ?? false);
	}

	public function getAction(int $id): ?array
	{
		$document = (new DocumentProvider())->getById($id);
		if ($document === null)
		{
			return null;
		}

		$recycleBinRepository = new RecycleBinRepository();
		$trashRecord = $recycleBinRepository->getByDocumentId($id);
		if ($trashRecord !== null)
		{
			$userId = (int)$this->getCurrentUser()->getId();
			if (!DocumentAccessService::canViewInRecycleBin($userId, $trashRecord))
			{
				$this->denyAccess();

				return null;
			}

			$canRestore = DocumentAccessService::canRestoreFromRecycleBin($userId, $trashRecord);
			$canHardDelete = DocumentAccessService::canHardDeleteFromRecycleBin($userId, $trashRecord);

			return $this->mapTrashedDocument($document, $trashRecord, $canRestore, $canHardDelete);
		}

		$collectionId = (int)$document->getCollectionId();
		$isArchived = (bool)$document->getIsArchived();
		$snapshot = DocumentAccessService::getCurrentUserSnapshot(
			$id,
			$collectionId,
			$isArchived,
			[DocumentAccessService::STATE_ARCHIVED],
		);
		if (!$snapshot['canView'])
		{
			$this->denyAccess();

			return null;
		}

		return $this->mapDocument($document, $snapshot);
	}

	/**
	 * Opening a document also answers with where it sits and what lies inside it. That is
	 * what feeds the screen inside a deleted or archived node and the "Documents inside" block on an
	 * ordinary page: the path is given whole, without folding the middle away - only a search card
	 * shortens it. The children counted are direct ones in the same state as the node itself, and a
	 * deleted node also names both dates of its deletion - the client cannot derive the second one.
	 */
	public function getOpenContextAction(int $id): ?array
	{
		// The size of the level and the dates of a deletion come with the context itself, so the
		// server-rendered page carries the same answer as this action.
		return (new DirectOpenContextService())->resolve($id);
	}

	public function listSharedWithMeAction(int $limit = 50, ?array $afterCursor = null): ?array
	{
		$userId = (int)$this->getCurrentUser()->getId();
		if ($userId <= 0)
		{
			return null;
		}

		$limit = max(1, min(200, $limit));
		$accessCodes = CollectionAccessService::buildUserAccessCodes($userId);

		return (new DocumentProvider())->getSharedWithMe($accessCodes, $limit, $afterCursor);
	}

	/**
	 * Pruned tree of documents reachable purely via document-level grants
	 * (no collection VIEW), grouped by container collection. Flat keyset pagination over the
	 * whole accessible set, ordered (COLLECTION_ID ASC, ID DESC); the client rebuilds the
	 * hierarchy from parentId.
	 */
	public function listAccessibleTreeAction(int $limit = 50, ?array $afterCursor = null): ?array
	{
		$userId = (int)$this->getCurrentUser()->getId();
		if ($userId <= 0)
		{
			return null;
		}

		$limit = max(1, min(200, $limit));
		$accessCodes = CollectionAccessService::buildUserAccessCodes($userId);

		return (new AccessibleTreeService())->list($userId, $accessCodes, $limit, $afterCursor);
	}

	/**
	 * Direct children of one document inside the accessible tree. listByParent cannot be
	 * reused: it gates on collection VIEW, which a document-grant recipient lacks by definition.
	 */
	public function listAccessibleChildrenAction(
		int $collectionId,
		int $parentId,
		int $limit = 50,
		?array $afterCursor = null,
	): ?array
	{
		$userId = (int)$this->getCurrentUser()->getId();
		if ($userId <= 0)
		{
			return null;
		}

		$limit = max(1, min(200, $limit));
		$accessCodes = CollectionAccessService::buildUserAccessCodes($userId);

		return (new AccessibleTreeService())->listChildren(
			$userId,
			$accessCodes,
			$collectionId,
			$parentId,
			$limit,
			$afterCursor,
		);
	}

	public function getPermissionsAction(int $id): ?array
	{
		$document = (new DocumentProvider())->getMetaById($id);
		if ($document === null)
		{
			return null;
		}

		$collectionId = (int)$document->getCollectionId();
		if (!$this->assertDocumentPermissionsAccess($collectionId))
		{
			return null;
		}

		return [
			'documentId' => $id,
			'permissions' => DocumentAccessService::getDocumentPermissions($id),
		];
	}

	public function getMyAccessAction(int $id): array
	{
		$document = (new DocumentProvider())->getMetaById($id);
		if ($document === null)
		{
			// Lost-access path: caller knows the document id but can no longer resolve it.
			// Return a fully-locked snapshot so the editor can tear down without leaking metadata.
			return [
				'documentId' => $id,
				'collectionId' => null,
				'canView' => false,
				'canEdit' => false,
				'canViewCollection' => false,
				'canEditCollection' => false,
				'canManagePermissions' => false,
				'sharedAccess' => true,
				'recycleBinId' => null,
				'trashedAt' => null,
				'canRestore' => false,
				'canHardDelete' => false,
				'isOrphan' => false,
			];
		}

		$collectionId = (int)$document->getCollectionId();

		// Trashed documents resolve access via the recycle-bin gate (mirrors getAction):
		// the author / trasher keep view access on an orphaned doc even after the source
		// collection — and thus the normal ACL path — is gone. Trashed docs are read-only.
		$trashRecord = (new RecycleBinRepository())->getByDocumentId($id);
		if ($trashRecord !== null)
		{
			$userId = (int)$this->getCurrentUser()->getId();
			$canView = DocumentAccessService::canViewInRecycleBin($userId, $trashRecord);
			$trashedAt = $trashRecord->getTrashedAt();

			// Action flags power the in-editor more-menu after a push-driven mode flip.
			// Outsiders get false-everything together with canView so they cannot enumerate trash rows.
			$canRestore = $canView && DocumentAccessService::canRestoreFromRecycleBin($userId, $trashRecord);
			$canHardDelete = $canView && DocumentAccessService::canHardDeleteFromRecycleBin($userId, $trashRecord);

			// Orphan-state mirrors mapTrashedDocument's logic — banner copy depends on it.
			$rawCollectionId = (int)$document->getCollectionId();
			// An orphan is a document with no base at all; a document of a deleted base is not one.
			$isOrphan = $rawCollectionId <= 0 || !$this->collectionExists($rawCollectionId);

			return [
				'documentId' => $id,
				'collectionId' => null,
				'canView' => $canView,
				'canEdit' => false,
				'canViewCollection' => false,
				'canEditCollection' => false,
				'canManagePermissions' => false,
				'sharedAccess' => true,
				// Editor uses these to power the in-place restore banner after a cascade trash
				// arrives via NOTE_COLLECTION_{id} without a per-doc payload — record is already
				// loaded above, so this is a free exposure.
				'recycleBinId' => $canView ? (int)$trashRecord->getId() : null,
				'trashedAt' => $canView ? $trashedAt->format('Y-m-d H:i:s') : null,
				'canRestore' => $canRestore,
				'canHardDelete' => $canHardDelete,
				'isOrphan' => $canView && $isOrphan,
			];
		}

		$snapshot = DocumentAccessService::getCurrentUserSnapshot(
			$id,
			$collectionId,
			(bool)$document->getIsArchived(),
			[DocumentAccessService::STATE_ARCHIVED],
		);

		$canView = (bool)($snapshot['canView'] ?? false);
		// The state of the base travels with the rights: the page has to say which notice it is
		// showing, and without it the editor learns a new state only from a reload.
		$collectionState = $canView
			? (new CollectionStateResolver())->resolve($collectionId)->value
			: null
		;

		return [
			'documentId' => $id,
			// Hide collection id when the user has no document access - same privacy rule as the REST read model.
			'collectionId' => $canView ? $collectionId : null,
			'canView' => $canView,
			'canEdit' => (bool)($snapshot['canEdit'] ?? false),
			'canViewCollection' => (bool)($snapshot['canViewCollection'] ?? false),
			'canEditCollection' => (bool)($snapshot['canEditCollection'] ?? false),
			'canManagePermissions' => (bool)($snapshot['canManagePermissions'] ?? false),
			'sharedAccess' => (bool)($snapshot['sharedAccess'] ?? true),
			'recycleBinId' => null,
			'trashedAt' => null,
			'canRestore' => false,
			'canHardDelete' => false,
			'isOrphan' => false,
			'collectionState' => $collectionState,
		];
	}

	/**
	 * Lazy body fetch for the version-preview popup. IDOR: rights and
	 * body are resolved by the version's own DOCUMENT_ID (VersionProvider), never by the
	 * $documentId passed in — a mismatch is treated the same as "does not exist" (404).
	 */
	public function getVersionAction(int $documentId, int $versionId): ?array
	{
		try
		{
			$version = (new VersionProvider())->getVersionBody($versionId);
		}
		catch (DocumentNotFoundException)
		{
			Context::getCurrent()->getResponse()->setStatus(404);

			return null;
		}
		catch (AccessDeniedException)
		{
			Context::getCurrent()->getResponse()->setStatus(403);
			$this->denyAccess();

			return null;
		}

		if ($version['documentId'] !== $documentId)
		{
			// Requested documentId does not match the version's real owner — 404, not 403,
			// so the response does not confirm the version exists elsewhere.
			Context::getCurrent()->getResponse()->setStatus(404);

			return null;
		}

		return [
			'id' => $version['id'],
			'markdown' => $version['markdown'],
			'title' => $version['title'],
			'createdAt' => $version['createdAt'],
			'createdBy' => $version['createdBy'],
			// N-1 snapshot for the preview diff ('' when this is the first version).
			'previousMarkdown' => $version['previousMarkdown'],
			'previousTitle' => $version['previousTitle'],
			'files' => (object)(new DocumentFilesMapService())->build($documentId),
		];
	}

	/**
	 * Restores document content to a past version's snapshot. Requires
	 * LEVEL_EDIT (server-checked, not just a disabled button). If the patch window is
	 * non-empty, rejects with 409 NOTE_RESTORE_DIRTY_WINDOW instead of silently discarding
	 * unsaved patches — the client is expected to compact() then retry (see SDD P1.T2).
	 *
	 * `operationId` is the client's name for this restore, echoed back in the overwrite push so the tab
	 * that asked for it can tell its own operation from a foreign write that landed first. It is not stored
	 * anywhere and it goes straight out into a notification channel, so only the agreed shape - 32 hex
	 * characters - travels on; anything else is treated as if the client had named nothing. Untyped for the
	 * same reason as the sync flags: the value comes off a request, where `operationId[]=x` arrives as an
	 * array, and a malformed name must cost the restore nothing.
	 */
	public function restoreVersionAction(int $documentId, int $versionId, $operationId = null): ?array
	{
		$document = (new DocumentProvider())->getMetaById($documentId);
		if ($document === null)
		{
			Context::getCurrent()->getResponse()->setStatus(404);

			return null;
		}

		if (!$this->assertDocumentEditAccess($documentId, (int)$document->getCollectionId()))
		{
			Context::getCurrent()->getResponse()->setStatus(403);

			return null;
		}

		if ((new DocumentUpdateRepository())->hasAnyByDocumentId($documentId))
		{
			Context::getCurrent()->getResponse()->setStatus(409);
			$this->addError(new Error(Loc::getMessage('NOTE_RESTORE_DIRTY_WINDOW'), 'NOTE_RESTORE_DIRTY_WINDOW'));

			return null;
		}

		$userId = (int)$this->getCurrentUser()->getId();
		try
		{
			(new RestoreDocumentVersionCommand(
				$documentId,
				$versionId,
				$userId,
				$this->normalizeOperationId($operationId),
			))->run();
		}
		catch (CommandException $e)
		{
			// AbstractCommand::run() is final and always wraps a thrown \Exception into a
			// CommandException — and RestoreDocumentVersionCommand nests OverwriteDocumentContentCommand,
			// so the real domain exception can be wrapped twice. Unwrap to the innermost cause
			// (mirrors Rest\V3\Controller\Document::mapDocumentDomainException).
			return $this->handleRestoreVersionFailure($this->unwrapCommandException($e));
		}

		return ['success' => true];
	}

	/**
	 * Activity feed of one document. Role visibility (MTX-01) is
	 * enforced by FeedProvider itself, before the caller's $types filter — the
	 * pre-filter narrows what is already visible, it never widens it.
	 */
	public function listFeedAction(
		int $documentId,
		array $types = [],
		int $limit = 50,
		?array $afterCursor = null,
	): ?array
	{
		try
		{
			return (new FeedProvider())->listByDocument($documentId, $types, $afterCursor, $limit);
		}
		catch (DocumentNotFoundException)
		{
			Context::getCurrent()->getResponse()->setStatus(404);

			return null;
		}
		catch (AccessDeniedException)
		{
			Context::getCurrent()->getResponse()->setStatus(403);
			$this->denyAccess();

			return null;
		}
	}

	/**
	 * "Who viewed the document" - list + unique counter. Plain
	 * LEVEL_VIEW, no role matrix (unlike listFeedAction): every viewer sees every
	 * other viewer.
	 */
	public function getViewsAction(int $documentId, int $limit = 50, ?array $afterCursor = null): ?array
	{
		try
		{
			return (new ViewProvider())->getViews($documentId, $limit, $afterCursor);
		}
		catch (DocumentNotFoundException)
		{
			Context::getCurrent()->getResponse()->setStatus(404);

			return null;
		}
		catch (AccessDeniedException)
		{
			Context::getCurrent()->getResponse()->setStatus(403);
			$this->denyAccess();

			return null;
		}
	}

	/**
	 * Documents linking to this one, keyset page. A missing document and a
	 * forbidden one answer alike — the list itself would otherwise tell the caller that some
	 * document exists behind the id.
	 *
	 * @param int|null $afterSourceId raw SOURCE_ID keyset cursor, echoed back as received
	 */
	public function getBacklinksAction(int $documentId, int $limit = 20, ?int $afterSourceId = null): ?array
	{
		try
		{
			return (new BacklinkProvider())->getSources($documentId, $limit, $afterSourceId);
		}
		catch (DocumentNotFoundException|AccessDeniedException)
		{
			return $this->denyBacklinks();
		}
	}

	/**
	 * Backlinks counter for the widget badge, capped at BacklinkProvider::COUNT_CAP.
	 */
	public function getBacklinksCountAction(int $documentId): ?array
	{
		try
		{
			return (new BacklinkProvider())->getCount($documentId);
		}
		catch (DocumentNotFoundException|AccessDeniedException)
		{
			return $this->denyBacklinks();
		}
	}

	/**
	 * The single refusal of both backlink actions: no document and no right are answered the same
	 * way, so the endpoints do not disclose whether the target exists.
	 */
	private function denyBacklinks(): ?array
	{
		Context::getCurrent()->getResponse()->setStatus(403);
		$this->denyAccess();

		return null;
	}

	/**
	 * The client's identifier for a restore, on its way to a push channel every editor of the document
	 * listens on. It is echoed verbatim and never stored, so it is admitted only in the agreed shape - 32
	 * hex characters - and anything else becomes "no identifier": an unnamed restore is still carried out,
	 * it just cannot be recognised by the tab that asked for it.
	 */
	private function normalizeOperationId(mixed $operationId): ?string
	{
		if (!is_string($operationId))
		{
			return null;
		}

		return preg_match('/^[0-9a-f]{32}$/i', $operationId) === 1 ? $operationId : null;
	}

	private function unwrapCommandException(\Throwable $e): \Throwable
	{
		while ($e instanceof CommandException && $e->getPrevious() !== null)
		{
			$e = $e->getPrevious();
		}

		return $e;
	}

	private function handleRestoreVersionFailure(\Throwable $cause): ?array
	{
		if ($cause instanceof DocumentNotFoundException)
		{
			Context::getCurrent()->getResponse()->setStatus(404);

			return null;
		}

		if ($cause instanceof DocumentInRecycleBinException)
		{
			Context::getCurrent()->getResponse()->setStatus(404);
			$this->addError(new Error(Loc::getMessage('NOTE_DOCUMENT_TRASHED'), 'DOCUMENT_TRASHED'));

			return null;
		}

		if ($cause instanceof DocumentArchivedException)
		{
			// Document exists and the request state is otherwise valid, but the archived
			// state blocks the operation — 409, same class of failure as the dirty-window
			// check above, and distinct from 200-with-body-error so the client does not
			// mistake this for a successful restore.
			Context::getCurrent()->getResponse()->setStatus(409);
			$this->addError(new Error(Loc::getMessage('NOTE_DOCUMENT_ARCHIVED'), 'DOCUMENT_ARCHIVED'));

			return null;
		}

		$this->addError(new Error(Loc::getMessage('NOTE_DOCUMENT_RESTORE_ERROR')));

		return null;
	}

	/**
	 * Full replacement of a document's manageable permissions.
	 * Each entry: { subjectCode, level: none|view|edit, scope?: document|subtree }. `scope` defaults
	 * to 'document'; 'subtree' is valid only with a positive level. Scope/level and feature-flag
	 * validation live in the service and surface here as error-collection entries.
	 *
	 * @param array<int, array{subjectCode: string, level: string, scope?: string}> $permissions
	 */
	public function savePermissionsAction(int $id, array $permissions = []): ?bool
	{
		$document = (new DocumentProvider())->getMetaById($id);
		if ($document === null)
		{
			return null;
		}

		$collectionId = (int)$document->getCollectionId();
		if (!$this->assertDocumentPermissionsAccess($collectionId))
		{
			return null;
		}

		$result = DocumentAccessService::replaceDocumentPermissions(
			$id,
			$permissions,
			(int)$this->getCurrentUser()->getId(),
		);

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return true;
	}

	public function getTreeAction(int $collectionId): ?array
	{
		try
		{
			$result = (new TreeProvider())->getAccessibleTree($collectionId);
		}
		catch (CollectionNotFoundException)
		{
			return null;
		}
		catch (AccessDeniedException)
		{
			$this->denyAccess();

			return null;
		}

		return $result['items'];
	}

	public function listByCollectionAction(
		int $collectionId,
		bool $ownedByMe = false,
		int $limit = 50,
		?array $afterCursor = null,
		bool $rootsOnly = false,
	): ?array
	{
		$userId = (int)$this->getCurrentUser()->getId();
		if ($userId <= 0)
		{
			return null;
		}

		$limit = max(1, min(200, $limit));

		try
		{
			return (new DocumentProvider())->getListByCollection(
				$collectionId,
				$ownedByMe,
				$userId,
				$limit,
				$afterCursor,
				$rootsOnly,
			);
		}
		catch (CollectionNotFoundException)
		{
			$this->addError(new Error(
				(string)(Loc::getMessage('NOTE_COLLECTION_NOT_FOUND')),
				'COLLECTION_NOT_FOUND',
			));

			return null;
		}
		catch (AccessDeniedException)
		{
			$this->addError(new Error(
				(string)(Loc::getMessage('NOTE_ACCESS_DENIED')),
				'ACCESS_DENIED',
			));

			return null;
		}
	}

	public function listByParentAction(
		int $collectionId,
		?int $parentId = null,
		int $limit = 50,
		?int $afterPosition = null,
		?int $afterId = null,
	): ?array
	{
		if (!$this->assertCollectionViewAccess($collectionId))
		{
			return null;
		}

		return (new TreeProvider())->getListByParent($collectionId, $parentId, $limit, $afterPosition, $afterId);
	}

	private function mapDocumentMeta(Document $document): array
	{
		return [
			'id' => $document->getId(),
			'collectionId' => $document->getCollectionId(),
			'parentId' => $document->getParentId(),
			'title' => $document->getTitle(),
			'position' => $document->getPosition(),
			'isArchived' => $document->getIsArchived(),
		];
	}

	/**
	 * Bootstrap payload of one document — the contract getAction hands the editor. Optional keys are
	 * absent, not null, and the client tells the two apart: `collectionId` present means the crumb
	 * opens the workspace, `yjsState` vs `markdown` selects the content path.
	 *
	 * `collaboration.canEnableCollaboration` false means the document was overwritten and can never go
	 * back to the collaborative format, so the client must not ask for a promotion. Read through the ORM
	 * cache here, hence a hint (see CollaborationEligibility); the uncached verdict rides on
	 * CollaborationSyncController::loadForCollaboration.
	 *
	 * @param array{canEdit: bool, sharedAccess: bool, canEditCollection: bool, canManagePermissions: bool} $snapshot
	 * @return array{
	 *   id: int, canEdit: bool, canEditCollection: bool, canManagePermissions: bool, sharedAccess: bool,
	 *   isMain: bool, parentId: int|null, title: string, contentFormat: string, position: int,
	 *   isArchived: bool, archivedAt: string|null, isTrashed: bool, recycleBinId: int|null,
	 *   trashedAt: string|null, isOrphan: bool, canRestore: bool,
	 *   createdBy: int, updatedBy: int, createdAt: string, updatedAt: string,
	 *   views: array<string, mixed>, lastChange: array<string, mixed>|null,
	 *   subscription: array<string, mixed>, collectionTitle: string,
	 *   ancestors: array<int, array{id: int, title: string}>,
	 *   collectionId?: int, sharedCollectionId?: int,
	 *   yjsState?: string, markdown?: string|array,
	 *   collaboration?: array<string, mixed>,
	 * }
	 */
	private function mapDocument(Document $document, ?array $snapshot = null): array
	{
		$documentId = (int)$document->getId();
		$collectionId = (int)$document->getCollectionId();
		$userId = (int)$this->getCurrentUser()->getId();
		$isArchived = (bool)$document->getIsArchived();

		$snapshot ??= DocumentAccessService::getCurrentUserSnapshot(
			$documentId,
			$collectionId,
			$isArchived,
			[DocumentAccessService::STATE_ARCHIVED],
		);
		$canEdit = $snapshot['canEdit'];
		$sharedAccess = $snapshot['sharedAccess'];
		$canEditCollection = $snapshot['canEditCollection'];
		$canManagePermissions = $snapshot['canManagePermissions'];

		$collabMeta = (new CollaborationProvider())->buildCollaborationMeta(
			$documentId,
			$collectionId,
			$userId,
			$canEdit,
			$document->getContentFormat(),
			$document->getMaterializedUptoId(),
		);

		$archivedAt = $document->getArchivedAt();

		$payload = [
			'id' => $document->getId(),
			'canEdit' => $canEdit,
			'canEditCollection' => $canEditCollection,
			'canManagePermissions' => $canManagePermissions,
			'sharedAccess' => $sharedAccess,
			'isMain' => (bool)$document->getIsMain(),
			'parentId' => $document->getParentId(),
			'title' => $document->getTitle(),
			'contentFormat' => $document->getContentFormat(),
			'position' => $document->getPosition(),
			'isArchived' => $isArchived,
			'archivedAt' => $archivedAt !== null ? $archivedAt->format('c') : null,
			'isTrashed' => false,
			'recycleBinId' => null,
			'trashedAt' => null,
			'isOrphan' => false,
			'canRestore' => false,
			'createdBy' => $document->getCreatedBy(),
			'updatedBy' => $document->getUpdatedBy(),
			'createdAt' => $document->getCreatedAt()->format('c'),
			'updatedAt' => $document->getUpdatedAt()->format('c'),
			// [#6] Initial "who viewed" snapshot bundled with the bootstrap payload — the
			// views widget uses this as its base and skips its own getViews call on mount.
			'views' => (new DocumentViewsSnapshotResolver())->resolve($documentId),
			// Activity-line chip's "last change" — same bootstrap-bundling rationale as views above.
			'lastChange' => (new DocumentLastChangeResolver())->resolve($documentId),
			// Bell state — bundled so the bell skips its own getState request on mount.
			'subscription' => (new DocumentSubscriptionStateResolver())->resolve($documentId, $userId),
			// Star state, bundled next to the bell for the same reason: the header adopts it
			// instead of asking for it on mount.
			'isFavorite' => $this->isFavorite($documentId, $userId),
			// Backlinks counter - same bootstrap-bundling rationale as views above.
			'backlinks' => (new DocumentBacklinksSnapshotResolver())->resolve($documentId),
		];

		$collectionTitle = '';
		$collection = (new CollectionProvider())->getById($collectionId);
		if ($collection !== null)
		{
			$collectionTitle = trim($collection->getName());
		}
		$payload['collectionTitle'] = $collectionTitle;
		// State of the base itself, not of the document: the path above the text names the base
		// wherever the document is read, but opens it only while the base is live.
		$payload['collectionState'] = (new CollectionStateResolver())->resolve($collectionId)->value;

		if (!$sharedAccess)
		{
			$payload['collectionId'] = $collectionId;
			$payload['ancestors'] = BreadcrumbAncestor::listToArray($this->buildAncestors($documentId));
		}
		else
		{
			// Shared access: the container collection is a label only (no collectionId, so the
			// breadcrumb renders it as plain text — the user cannot open the workspace), and the
			// ancestor chain is cut at the first ancestor the user may not see.
			$payload['ancestors'] = BreadcrumbAncestor::listToArray(
				(new AccessibleAncestorResolver())->resolve($documentId, $collectionId, $userId),
			);
			// Container id for the accessible-tree namespace: the children block and the "Shared
			// with me" branches are keyed by it. Deliberately NOT collectionId — that field is what
			// makes the breadcrumb container clickable, and this workspace stays closed. The id is
			// no secret here: listAccessibleTree already hands the same user their container ids.
			$payload['sharedCollectionId'] = $collectionId;
		}

		$yjsState = $document->getYjsState();
		if (
			$document->getContentFormat() === DocumentTable::CONTENT_FORMAT_YJS
			&& $yjsState !== null
			&& $yjsState !== ''
		)
		{
			$payload['yjsState'] = $yjsState;
		}
		else
		{
			$payload['markdown'] = $document->getMarkdown();
		}

		if ($collabMeta !== null)
		{
			$payload['collaboration'] = $collabMeta;
		}

		// An object even when empty: the client reads it as a map by file id.
		$payload['files'] = (object)(new DocumentFilesMapService())->build($documentId);

		return $payload;
	}

	private function mapTrashedDocument(
		Document $document,
		RecycleBinRecord $record,
		bool $canRestore,
		bool $canHardDelete = false,
	): array
	{
		$rawCollectionId = (int)$document->getCollectionId();
		// Presence of the base, not its state: an archived base is named in the bin on purpose,
		// and its documents are not orphans.
		$hasCollection = false;
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
				$hasCollection = true;
				$collectionTitle = (string)($row['NAME'] ?? '');
			}
		}

		$exposedCollectionId = $hasCollection ? $rawCollectionId : 0;
		$exposedCollectionTitle = $hasCollection ? $collectionTitle : '';

		$payload = [
			'id' => (int)$document->getId(),
			'canEdit' => false,
			'canEditCollection' => false,
			'canManagePermissions' => false,
			'sharedAccess' => false,
			'parentId' => $document->getParentId(),
			'title' => $document->getTitle(),
			'contentFormat' => $document->getContentFormat(),
			'position' => $document->getPosition(),
			'isArchived' => (bool)$document->getIsArchived(),
			'archivedAt' => $document->getArchivedAt()?->format('c'),
			'isTrashed' => true,
			'recycleBinId' => (int)$record->getId(),
			'trashedAt' => $record->getTrashedAt()->format('c'),
			'isOrphan' => !$hasCollection,
			'canRestore' => $canRestore,
			'canHardDelete' => $canHardDelete,
			'createdBy' => $document->getCreatedBy(),
			'updatedBy' => $document->getUpdatedBy(),
			'createdAt' => $document->getCreatedAt()->format('c'),
			'updatedAt' => $document->getUpdatedAt()->format('c'),
			'collectionId' => $exposedCollectionId,
			'collectionTitle' => $exposedCollectionTitle,
			'ancestors' => [],
			'views' => (new DocumentViewsSnapshotResolver())->resolve((int)$document->getId()),
			'lastChange' => (new DocumentLastChangeResolver())->resolve((int)$document->getId()),
			'subscription' => (new DocumentSubscriptionStateResolver())->resolve((int)$document->getId(), (int)$this->getCurrentUser()->getId()),
			'backlinks' => (new DocumentBacklinksSnapshotResolver())->resolve((int)$document->getId()),
			'isFavorite' => $this->isFavorite((int)$document->getId(), (int)$this->getCurrentUser()->getId()),
		];

		$yjsState = $document->getYjsState();
		if (
			$document->getContentFormat() === DocumentTable::CONTENT_FORMAT_YJS
			&& $yjsState !== null
			&& $yjsState !== ''
		)
		{
			$payload['yjsState'] = $yjsState;
		}
		else
		{
			$payload['markdown'] = $document->getMarkdown();
		}

		return $payload;
	}

	/**
	 * A base in the bin is governed by one right, and it is not the right to read: its content is
	 * open to whoever may manage the bin and to nobody else, so a reader holding VIEW alone is
	 * answered as for a base that is not there.
	 */
	private function assertCollectionViewAccess(int $collectionId): bool
	{
		if (!DocumentAccessService::isCollectionAlive($collectionId))
		{
			$userId = (int)$this->getCurrentUser()->getId();
			if (DocumentAccessService::canManageCollectionInRecycleBin($userId, $collectionId))
			{
				return true;
			}

			$this->addError(new Error(
				(string)Loc::getMessage('NOTE_COLLECTION_NOT_FOUND'),
				'COLLECTION_NOT_FOUND',
			));

			return false;
		}

		if ($this->hasCollectionLevel($collectionId, CollectionAccessService::LEVEL_VIEW))
		{
			return true;
		}

		$this->denyAccess();

		return false;
	}

	/**
	 * Star state of one document for the bootstrap payload. Unknown means not starred, the
	 * same safe default the bundled bell state degrades to.
	 */
	private function isFavorite(int $documentId, int $userId): bool
	{
		return (new FavoriteRepository())->findRow(
			$userId,
			FavoriteTable::ENTITY_TYPE_DOCUMENT,
			$documentId,
		) !== null;
	}

	private function assertCollectionManageAccess(int $collectionId): bool
	{
		if ($this->hasCollectionLevel($collectionId, CollectionAccessService::LEVEL_MANAGE))
		{
			return true;
		}

		$this->denyAccess();

		return false;
	}

	private function assertDocumentViewAccess(int $documentId, int $collectionId): bool
	{
		if (DocumentAccessService::currentUserHasLevel($documentId, $collectionId, DocumentAccessService::LEVEL_VIEW))
		{
			return true;
		}

		$this->denyAccess();

		return false;
	}

	private function assertDocumentEditAccess(int $documentId, int $collectionId): bool
	{
		if (DocumentAccessService::currentUserHasLevel($documentId, $collectionId, DocumentAccessService::LEVEL_EDIT))
		{
			return true;
		}

		$this->denyAccess();

		return false;
	}

	private function hasCollectionLevel(int $collectionId, int $requiredLevel): bool
	{
		return CollectionAccessService::currentUserHasLevel($collectionId, $requiredLevel);
	}

	private function assertDocumentPermissionsAccess(int $collectionId): bool
	{
		if (CollectionAccessService::currentUserHasLevel($collectionId, CollectionAccessService::LEVEL_MODERATE))
		{
			return true;
		}

		$this->denyAccess();

		return false;
	}

	private function denyAccess(): void
	{
		$this->addError(new Error((string)(Loc::getMessage('NOTE_ACCESS_DENIED'))));
	}

	/**
	 * Presence of the base row, which is what an orphan is the absence of: a base in the recycle bin
	 * keeps its row and its documents go back with it.
	 */
	private function collectionExists(int $collectionId): bool
	{
		return DocumentAccessService::collectionState($collectionId) !== null;
	}

	/**
	 * @return BreadcrumbAncestor[] ordered from the collection root down to the direct parent
	 */
	private function buildAncestors(int $documentId): array
	{
		if ($documentId <= 0)
		{
			return [];
		}

		$path = (new DocumentRepository())->getDocumentPathToRoot($documentId);
		$result = [];
		foreach ($path as $ancestor)
		{
			$id = (int)$ancestor->getId();
			if ($id <= 0)
			{
				continue;
			}

			$result[] = new BreadcrumbAncestor($id, (string)$ancestor->getTitle());
		}

		return $result;
	}

}
