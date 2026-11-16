<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Controller;

use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\SystemException;
use Bitrix\Note\Infrastructure\Controller\ActionFilter;
use Bitrix\Note\Internal\Access\Service\CollectionAccessService;
use Bitrix\Note\Internal\Access\Service\DocumentAccessService;
use Bitrix\Note\Internal\Configuration;
use Bitrix\Note\Internal\Entity\RecycleBin\RecycleBinRecord;
use Bitrix\Note\Internal\Exceptions\OrphanRestoreTargetRequiredException;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Repository\RecycleBinRepository;
use Bitrix\Note\Internal\Service\Bulk\RestoreOrderer;
use Bitrix\Note\Internal\Service\Bulk\SelectionResolver;
use Bitrix\Note\Internal\Service\RecycleBin\RestoreFromRecycleBinService;
use Bitrix\Note\Public\Command\BulkHardDeleteDocumentsCommand;
use Bitrix\Note\Public\Command\BulkRestoreDocumentsCommand;
use Bitrix\Note\Public\Command\EmptyRecycleBinCommand;
use Bitrix\Note\Public\Command\HardDeleteCollectionCommand;
use Bitrix\Note\Public\Command\HardDeleteDocumentCommand;
use Bitrix\Note\Public\Command\RestoreAllFromRecycleBinCommand;
use Bitrix\Note\Public\Command\RestoreCollectionFromRecycleBinCommand;
use Bitrix\Note\Public\Command\RestoreDocumentFromRecycleBinCommand;
use Bitrix\Note\Public\Provider\ContentList\LevelProvider;
use Bitrix\Note\Public\Provider\ContentList\SectionLevelPreloader;
use Bitrix\Note\Public\Provider\ContentList\SectionProvider;
use Bitrix\Note\Public\Provider\RecycleBinProvider;

class RecycleBinController extends Controller
{
	use LevelScopeTrait;

	public const ERROR_ORPHAN_TARGET_REQUIRED = 'NOTE_RECYCLE_BIN_ORPHAN_TARGET_REQUIRED';

	/** Shape of the target: a live base named by id. */
	private const TARGET_COLLECTION = 'collection';

	// What one request spends on the bases of a selection, shared by all of them. The same slice
	// EmptyRecycleBinCommand and HardDeleteCollectionCommand work within.
	private const COLLECTIONS_BUDGET_SECONDS = 10.0;

	protected function getDefaultPreFilters(): array
	{
		return array_merge(
			parent::getDefaultPreFilters(),
			[
				new ActionFilter\NoteAccess(),
			],
		);
	}

	public function listAction(int $limit = 50, ?array $afterCursor = null): ?array
	{
		$userId = (int)$this->getCurrentUser()->getId();
		if ($userId <= 0)
		{
			return null;
		}

		$limit = max(1, min(200, $limit));
		$accessCodes = CollectionAccessService::buildUserAccessCodes($userId);

		$payload = (new RecycleBinProvider())->getRecycleBinForUser($accessCodes, $userId, $limit, $afterCursor);
		$payload['isAdmin'] = DocumentAccessService::isPortalAdmin($userId);

		return $payload;
	}

	/**
	 * Knowledge bases that hold anything in the bin, with their counters. Freshest
	 * operation first - a person comes here to sort out yesterday. The list of bases is paged: the
	 * answer names the base it stopped at, and the next page is asked for from there.
	 *
	 * The answer also carries the first page of the sections the reader lands on, so that opening the
	 * bin is one request and not one per knowledge base. The rest of the sections are asked for as
	 * they scroll into view - see `levels` in {@see SectionLevelPreloader}.
	 *
	 * @param array{freshness: string, collectionId: int}|null $cursor
	 */
	public function sectionsAction(?array $cursor = null): ?array
	{
		$userId = (int)$this->getCurrentUser()->getId();
		if ($userId <= 0)
		{
			return null;
		}

		$payload = (new SectionProvider())->getRecycleBinSections($userId, $cursor);
		$payload['levels'] = SectionLevelPreloader::forRecycleBin($userId, $payload['sections']);

		return $payload;
	}

	/**
	 * One level inside the bin: the top level of a section, or the children of one trashed
	 * node. A null collectionId asks for the technical "no knowledge base" section.
	 *
	 * @param array{changedAt: string, id: int}|null $cursor
	 */
	public function levelAction(
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

		// Inside a node the caller names only the node, so the base is read off the parent: asking by
		// parent alone must not be a way around the rights on the base. A parent with no base at all
		// is left to the per-entry rights of the bin - that is the technical section, whose documents
		// answer to their author and deleter.
		$scopeCollectionId = $this->resolveLevelScopeCollectionId($collectionId, $parentId);
		if ($scopeCollectionId === self::LEVEL_SCOPE_CONFLICT)
		{
			$this->denyAccess();

			return null;
		}
		if ($scopeCollectionId > 0 && !$this->userCanViewCollection($userId, $scopeCollectionId))
		{
			$this->denyAccess();

			return null;
		}

		return (new LevelProvider())->getRecycleBinLevel($userId, $collectionId, $parentId, $cursor, $limit);
	}

	/**
	 * The top level of several sections at once - the sections the reader scrolled to, past the ones
	 * whose content arrived with the list. Sections come up open, so asking for them one by one is
	 * one request per section, each with a bootstrap of the core of its own; the list is fifty
	 * sections long and only the first few travel with it.
	 *
	 * The answer carries `levels` in the shape the section list carries it, so the client reads
	 * both with the same parser. A section it does not name is one there was nothing to answer for -
	 * see {@see SectionLevelPreloader::forRecycleBinCollections()} - and is asked for the usual way.
	 *
	 * @param int[] $collectionIds no more than SectionLevelPreloader::MAX_BATCH_COLLECTIONS answer
	 * @return array{levels: array<string, array{items: array, nextCursor: ?array}>}|null
	 */
	public function levelsAction(array $collectionIds, int $limit = LevelProvider::DEFAULT_LIMIT): ?array
	{
		$userId = (int)$this->getCurrentUser()->getId();
		if ($userId <= 0)
		{
			return null;
		}

		return ['levels' => SectionLevelPreloader::forRecycleBinCollections($userId, $collectionIds, $limit)];
	}

	public function restoreDocumentAction(int $recycleBinId, ?int $targetCollectionId = null): ?array
	{
		$userId = (int)$this->getCurrentUser()->getId();
		if ($userId <= 0)
		{
			return null;
		}

		$record = (new RecycleBinRepository())->getById($recycleBinId);
		if ($record === null)
		{
			$this->addError(new Error(Loc::getMessage('NOTE_DOCUMENT_TRASH_RESTORE_ERROR')));

			return null;
		}

		if (!DocumentAccessService::canRestoreFromRecycleBin($userId, $record))
		{
			$this->denyAccess();

			return null;
		}

		if ($targetCollectionId !== null && $targetCollectionId > 0 && !$this->userCanManageCollection($userId, $targetCollectionId))
		{
			$this->denyAccess();

			return null;
		}

		try
		{
			$result = (new RestoreDocumentFromRecycleBinCommand($recycleBinId, $userId, $targetCollectionId))->run();
		}
		catch (OrphanRestoreTargetRequiredException)
		{
			$this->addError(new Error(
				Loc::getMessage('NOTE_DOCUMENT_TRASH_ORPHAN_TARGET_REQUIRED'),
				self::ERROR_ORPHAN_TARGET_REQUIRED,
			));

			return null;
		}
		catch (SystemException)
		{
			$this->addError(new Error(Loc::getMessage('NOTE_DOCUMENT_TRASH_RESTORE_ERROR')));

			return null;
		}

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return [
			'documentId' => (int)($result->getData()['documentId'] ?? $record->getDocumentId()),
		];
	}

	/**
	 * Bulk restore of a selection from the recycle bin.
	 * A selected card means its whole branch by default: the unit of restoring is the unit of
	 * deleting, and the composition is expanded on the server because the client loads it lazily and
	 * does not have it at the moment of the click.
	 * canRestore is decided per record inside the command; records the user cannot restore land
	 * in skippedByAccessCount. Jointly selected parent/child pairs are restored top-down so the
	 * child re-attaches under its parent.
	 *
	 * Orphans whose original collection is gone need targetCollectionId. Without it they are
	 * reported as skippedOrphanCount (partial success, never rolled back) and the picker code
	 * NOTE_RECYCLE_BIN_ORPHAN_TARGET_REQUIRED is surfaced alongside the outcome.
	 *
	 * The bulk contract of the module (API-03), the same one the archive answers by, and the surface
	 * every caller but the destination flow of the recycle bin page uses - see {@see restoreAction()}
	 * for why that one is separate.
	 *
	 * The branch is what the interface offers, but withNested defaults to off all the same: the
	 * parameter was added to an action that already existed, and a caller from before it - a browser
	 * still holding the previous bundle, first of all - has to keep getting what it used to ask for.
	 * The client of this surface always sends the flag.
	 *
	 * @param int[] $recycleBinIds
	 */
	public function restoreManyAction(
		array $recycleBinIds,
		?int $targetCollectionId = null,
		bool $withNested = false,
	): ?array
	{
		$userId = (int)$this->getCurrentUser()->getId();
		if ($userId <= 0)
		{
			return null;
		}

		$entryIds = array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $recycleBinIds),
			static fn(int $id): bool => $id > 0,
		)));
		if (empty($entryIds))
		{
			$this->addError(new Error(Loc::getMessage('NOTE_BULK_EMPTY_SELECTION'), 'NOTE_BULK_EMPTY_SELECTION'));

			return null;
		}

		// Checked before the lookup: the list goes into a single IN before the bulk pipeline gets to its own cap.
		if (count($entryIds) > Configuration::getBulkSelectionLimit())
		{
			$this->addError(new Error(Loc::getMessage('NOTE_BULK_LIMIT_EXCEEDED'), 'NOTE_BULK_LIMIT_EXCEEDED'));

			return null;
		}

		$normalizedTarget = $targetCollectionId !== null && $targetCollectionId > 0 ? $targetCollectionId : null;
		if ($normalizedTarget !== null && !$this->userCanManageCollection($userId, $normalizedTarget))
		{
			$this->denyAccess();

			return null;
		}

		// The bulk pipeline is document-id based; translate the selected recycle-bin entries.
		$documentIds = [];
		foreach ((new RecycleBinRepository())->getByIds($entryIds) as $record)
		{
			$documentId = (int)$record->getDocumentId();
			if ($documentId > 0)
			{
				$documentIds[] = $documentId;
			}
		}
		if (empty($documentIds))
		{
			$this->addError(new Error(Loc::getMessage('NOTE_BULK_EMPTY_SELECTION'), 'NOTE_BULK_EMPTY_SELECTION'));

			return null;
		}

		try
		{
			$outcome = (new BulkRestoreDocumentsCommand(
				$userId,
				$documentIds,
				SelectionResolver::SECTION_RECYCLE,
				$normalizedTarget,
				$withNested,
			))->run()->getData()['outcome'] ?? null;
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

		if ($normalizedTarget === null && (int)($outcome['skippedOrphanCount'] ?? 0) > 0)
		{
			$this->addError(new Error(
				Loc::getMessage('NOTE_DOCUMENT_TRASH_ORPHAN_TARGET_REQUIRED'),
				self::ERROR_ORPHAN_TARGET_REQUIRED,
			));
		}

		return ['outcome' => $outcome];
	}

	public function getStatsAction(): ?array
	{
		$userId = (int)$this->getCurrentUser()->getId();
		if ($userId <= 0)
		{
			return null;
		}

		$accessCodes = CollectionAccessService::buildUserAccessCodes($userId);

		return (new RecycleBinProvider())->getStatsForUser($userId, $accessCodes);
	}

	public function restoreAllAction(?int $orphanTargetCollectionId = null): ?array
	{
		$userId = (int)$this->getCurrentUser()->getId();
		if ($userId <= 0)
		{
			return null;
		}

		$accessCodes = CollectionAccessService::buildUserAccessCodes($userId);

		$normalizedTarget = $orphanTargetCollectionId !== null && $orphanTargetCollectionId > 0
			? $orphanTargetCollectionId
			: null
		;

		if ($normalizedTarget !== null && !$this->userCanManageCollection($userId, $normalizedTarget))
		{
			$this->denyAccess();

			return null;
		}

		try
		{
			$result = (new RestoreAllFromRecycleBinCommand($userId, $accessCodes, $normalizedTarget))->run();
		}
		catch (SystemException)
		{
			$this->addError(new Error(Loc::getMessage('NOTE_TRASH_RESTORE_ALL_ERROR')));

			return null;
		}

		$data = $result->getData();

		return [
			'restored' => (int)($data['restoredCount'] ?? 0),
			'skippedOrphan' => (int)($data['skippedOrphans'] ?? 0),
			// The bases of the bin come back with everything else here: the emptying takes them away,
			// so the restore has to reach exactly as far.
			'restoredCollections' => (int)($data['restoredCollectionCount'] ?? 0),
			'failedCollections' => $data['failedCollections'] ?? [],
		];
	}

	public function hardDeleteDocumentAction(int $recycleBinId): ?array
	{
		$userId = (int)$this->getCurrentUser()->getId();
		if ($userId <= 0)
		{
			return null;
		}

		$record = (new RecycleBinRepository())->getById($recycleBinId);
		if ($record === null)
		{
			$this->addError(new Error(Loc::getMessage('NOTE_DOCUMENT_TRASH_HARD_DELETE_ERROR')));

			return null;
		}

		if (!DocumentAccessService::canHardDeleteFromRecycleBin($userId, $record))
		{
			$this->denyAccess();

			return null;
		}

		try
		{
			$result = (new HardDeleteDocumentCommand($recycleBinId, $userId))->run();
		}
		catch (SystemException)
		{
			$this->addError(new Error(Loc::getMessage('NOTE_DOCUMENT_TRASH_HARD_DELETE_ERROR')));

			return null;
		}

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return [
			'documentId' => (int)($result->getData()['documentId'] ?? 0),
		];
	}

	/**
	 * Bulk hard-delete of a selection from the recycle bin, branch by branch for the same reason
	 * restoring is.
	 * canHardDelete is decided per record inside the command (live collection -> MANAGE, orphan ->
	 * trashedBy / portal admin); records the user cannot hard-delete land in skippedByAccessCount,
	 * they are not an endpoint error. Files and the search index are cleaned up after each chunk's
	 * commit, outside the transaction.
	 *
	 * withNested defaults to off, and here that matters more than anywhere else on this surface: the
	 * parameter is new on an action that is not, and what a caller from before it would get by
	 * accident is the permanent removal of everything under the records it named.
	 *
	 * A selection may name whole knowledge bases alongside single records, the same way restoring
	 * does, and a base goes away whole with everything inside it. The bases are taken last: they are
	 * the heavier half, and what the budget does not reach keeps its place in the bin.
	 *
	 * @param int[] $recycleBinIds
	 * @param int[] $collectionIds knowledge bases to take away whole
	 */
	// Both lists default to empty for the reason restoreAction does: a selection of nothing but
	// bases carries no entry ids, and an empty array arrives as a missing parameter.
	public function hardDeleteManyAction(
		array $recycleBinIds = [],
		bool $withNested = false,
		array $collectionIds = [],
	): ?array
	{
		$userId = (int)$this->getCurrentUser()->getId();
		if ($userId <= 0)
		{
			return null;
		}

		$entryIds = array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $recycleBinIds),
			static fn(int $id): bool => $id > 0,
		)));
		$baseIds = array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $collectionIds),
			static fn(int $id): bool => $id > 0,
		)));
		if (empty($entryIds) && empty($baseIds))
		{
			$this->addError(new Error(Loc::getMessage('NOTE_BULK_EMPTY_SELECTION'), 'NOTE_BULK_EMPTY_SELECTION'));

			return null;
		}

		// Checked before the lookup: the list goes into a single IN before the bulk pipeline gets to its own cap.
		if (count($entryIds) + count($baseIds) > Configuration::getBulkSelectionLimit())
		{
			$this->addError(new Error(Loc::getMessage('NOTE_BULK_LIMIT_EXCEEDED'), 'NOTE_BULK_LIMIT_EXCEEDED'));

			return null;
		}

		// Rights on every named base before the first write: the all-or-nothing rule restoring
		// answers to, and the more important of the two, since nothing here can be taken back.
		foreach ($baseIds as $collectionId)
		{
			if (!DocumentAccessService::canManageCollectionInRecycleBin($userId, $collectionId))
			{
				$this->denyAccess();

				return null;
			}
		}

		// The bulk pipeline is document-id based; translate the selected recycle-bin entries.
		$documentIds = [];
		foreach ((new RecycleBinRepository())->getByIds($entryIds) as $record)
		{
			$documentId = (int)$record->getDocumentId();
			if ($documentId > 0)
			{
				$documentIds[] = $documentId;
			}
		}
		if (empty($documentIds) && empty($baseIds))
		{
			$this->addError(new Error(Loc::getMessage('NOTE_BULK_EMPTY_SELECTION'), 'NOTE_BULK_EMPTY_SELECTION'));

			return null;
		}

		if (empty($documentIds))
		{
			return $this->hardDeleteSelectedCollections($baseIds);
		}

		try
		{
			$outcome = (new BulkHardDeleteDocumentsCommand($userId, $documentIds, $withNested))
				->run()
				->getData()['outcome'] ?? null
			;
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

		if (empty($baseIds))
		{
			return ['outcome' => $outcome];
		}

		return array_merge(['outcome' => $outcome], $this->hardDeleteSelectedCollections($baseIds));
	}

	/**
	 * Takes the named bases away whole, one by one and on one shared budget: a base sent to the bin
	 * carries every document it ever had, so a selection of them is far past what a single request
	 * may hold. What the budget does not reach keeps its place in the bin, and completed=false says
	 * so - the cleanup agent takes away the rest on its own.
	 *
	 * @param int[] $collectionIds
	 * @return array{deletedCollectionIds: int[], failedCollections: array, completed: bool}
	 */
	private function hardDeleteSelectedCollections(array $collectionIds): array
	{
		$deadline = microtime(true) + self::COLLECTIONS_BUDGET_SECONDS;
		$deleted = [];
		$failed = [];
		$completed = true;

		foreach ($collectionIds as $collectionId)
		{
			if (microtime(true) > $deadline)
			{
				$completed = false;

				break;
			}

			try
			{
				$result = (new HardDeleteCollectionCommand(
					$collectionId,
					deadline: $deadline,
				))->run();
			}
			catch (SystemException)
			{
				$failed[] = ['collectionId' => $collectionId];

				continue;
			}

			if (!$result->isSuccess())
			{
				// A base held by an operation somebody else started stays for whoever holds it.
				$failed[] = ['collectionId' => $collectionId];

				continue;
			}

			// The budget stopped this base half-way: it keeps the rest of its documents and its
			// place in the bin, so the answer must not count it as gone.
			if (($result->getData()['completed'] ?? true) === false)
			{
				$completed = false;

				break;
			}

			$deleted[] = $collectionId;
		}

		return [
			'deletedCollectionIds' => $deleted,
			'failedCollections' => $failed,
			'completed' => $completed,
		];
	}

	/**
	 * Brings a whole knowledge base back from the bin, with the tree it had when it was deleted. One
	 * action and not a selection of its documents: the base went there as a whole, and what was in the
	 * bin before it stays there.
	 */
	public function restoreCollectionAction(int $collectionId): ?array
	{
		$userId = (int)$this->getCurrentUser()->getId();
		if ($userId <= 0)
		{
			return null;
		}

		// One right governs a base lying in the bin, and it answers "no" for a base that is not there.
		if (!DocumentAccessService::canManageCollectionInRecycleBin($userId, $collectionId))
		{
			$this->denyAccess();

			return null;
		}

		try
		{
			$result = (new RestoreCollectionFromRecycleBinCommand($collectionId, $userId))->run();
		}
		catch (SystemException)
		{
			$this->addError(new Error(Loc::getMessage('NOTE_COLLECTION_TRASH_RESTORE_ERROR')));

			return null;
		}

		$data = $result->getData();
		if (($data['success'] ?? false) !== true)
		{
			$this->addError($this->collectionRestoreError($data['errorCode'] ?? null));

			return null;
		}

		return [
			'collectionId' => $collectionId,
			'restoredCount' => (int)($data['restoredCount'] ?? 0),
		];
	}

	/**
	 * Removes a knowledge base from the bin for good. Offered on the page of the base itself, opened
	 * from the bin, and nowhere in the list: what is going away has to be visible before it goes.
	 */
	public function hardDeleteCollectionAction(int $collectionId): ?array
	{
		$userId = (int)$this->getCurrentUser()->getId();
		if ($userId <= 0)
		{
			return null;
		}

		if (!DocumentAccessService::canManageCollectionInRecycleBin($userId, $collectionId))
		{
			$this->denyAccess();

			return null;
		}

		try
		{
			$result = (new HardDeleteCollectionCommand($collectionId))->run();
		}
		catch (SystemException)
		{
			$this->addError(new Error(Loc::getMessage('NOTE_COLLECTION_TRASH_HARD_DELETE_ERROR')));

			return null;
		}

		if (!$result->isSuccess())
		{
			$this->addError(new Error(Loc::getMessage('NOTE_COLLECTION_TRASH_HARD_DELETE_ERROR')));

			return null;
		}

		$data = $result->getData();

		// completed=false means the request took away its slice and stopped: the base keeps the rest
		// of its documents and its place in the bin, so the page must not report it gone.
		return [
			'collectionId' => $collectionId,
			'deleted' => (int)($data['deletedCount'] ?? 0),
			'completed' => (bool)($data['completed'] ?? true),
		];
	}

	private function collectionRestoreError(?string $errorCode): Error
	{
		$message = match ($errorCode)
		{
			RestoreCollectionFromRecycleBinCommand::ERROR_RETENTION_EXPIRED
				=> Loc::getMessage('NOTE_COLLECTION_TRASH_RESTORE_EXPIRED'),
			RestoreCollectionFromRecycleBinCommand::ERROR_RESTORE_IN_PROGRESS
				=> Loc::getMessage('NOTE_COLLECTION_TRASH_RESTORE_IN_PROGRESS'),
			RestoreCollectionFromRecycleBinCommand::ERROR_PURGE_STARTED
				=> Loc::getMessage('NOTE_COLLECTION_TRASH_RESTORE_PURGE_STARTED'),
			default => Loc::getMessage('NOTE_COLLECTION_TRASH_RESTORE_ERROR'),
		};

		return new Error((string)$message, $errorCode ?? 'NOTE_COLLECTION_TRASH_RESTORE_ERROR');
	}

	public function emptyAction(): ?array
	{
		$userId = (int)$this->getCurrentUser()->getId();
		if ($userId <= 0)
		{
			return null;
		}

		$accessCodes = CollectionAccessService::buildUserAccessCodes($userId);

		try
		{
			$result = (new EmptyRecycleBinCommand($userId, $accessCodes))->run();
		}
		catch (SystemException)
		{
			$this->addError(new Error(Loc::getMessage('NOTE_TRASH_EMPTY_ERROR')));

			return null;
		}

		$data = $result->getData();

		// completed=false means the request took away its slice and stopped: the rest of the bin is
		// still there and the page must not report an empty bin.
		//
		// Bases are counted with the documents: the list of the bin shows a deleted base as a card of
		// its own, so a bin holding nothing but bases would otherwise report nothing deleted.
		return [
			'deleted' => (int)($data['deletedCount'] ?? 0) + (int)($data['deletedCollectionCount'] ?? 0),
			'completed' => (bool)($data['completed'] ?? true),
		];
	}

	/**
	 * Restore of a selection with a choice of where it lands. An orphan - a document whose knowledge
	 * base was deleted - has nowhere of its own to go back to, so the caller names a live base for it,
	 * and the question is asked once per operation, not once per document.
	 *
	 * The second restore surface of this controller, and deliberately the narrower one:
	 * {@see restoreManyAction()} owns the bulk contract shared with the archive (branch expansion by
	 * withNested, partial success reported in skippedByAccessCount), while this one answers the
	 * question of the destination for the whole selection at once and refuses it whole when the
	 * answer does not hold - the page that asks where things land may not discover halfway through
	 * that part of the selection is already out of the bin. The price is paid per record, hence the
	 * selection cap below. The two converge when the bulk pipeline learns to carry the destination
	 * question with the same all-or-nothing answer; until then this action belongs to the recycle bin
	 * page alone and nothing else may call it.
	 *
	 * A selection may name whole knowledge bases alongside single records, and it travels in one
	 * call: the reader made one choice, so it is one operation. A base goes back whole - the records
	 * of its own tree are not named and must not be, the pre-flight below refuses them while their
	 * base is in the bin - and the bases are taken first, so what is left in the bin afterwards is
	 * what was thrown away inside them on their own.
	 *
	 * @param int[] $entryIds
	 * @param int[] $collectionIds knowledge bases to bring back whole
	 * @param array{kind: string, collectionId?: int}|null $target
	 * @return array{restoredIds: int[], restoredCollectionIds: int[], failedCollections: array}|null
	 */
	// A selection of nothing but bases carries no entry ids at all, and an empty array does not
	// survive the form encoding of the request - the parameter arrives missing, not empty. Hence the
	// default: the emptiness of both is answered below, by the caller's own error.
	public function restoreAction(array $entryIds = [], ?array $target = null, array $collectionIds = []): ?array
	{
		$userId = (int)$this->getCurrentUser()->getId();
		if ($userId <= 0)
		{
			return null;
		}

		$ids = array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $entryIds),
			static fn(int $id): bool => $id > 0,
		)));
		$baseIds = array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $collectionIds),
			static fn(int $id): bool => $id > 0,
		)));
		if (empty($ids) && empty($baseIds))
		{
			$this->addError(new Error((string)Loc::getMessage('NOTE_BULK_EMPTY_SELECTION')));

			return null;
		}

		// The cap the rest of the bulk surface answers to. This action restores record by record,
		// each with its own reads, transaction, reindexing and notifications, so a hand-made call
		// with a long list buys an unbounded amount of work for the price of one request. A base
		// counts as the one unit it is here, and it is paid for like one: what it costs is a pass
		// over its own rows, not a pass per row of the selection.
		if (count($ids) + count($baseIds) > Configuration::getBulkSelectionLimit())
		{
			$this->addError(new Error(Loc::getMessage('NOTE_BULK_LIMIT_EXCEEDED'), 'NOTE_BULK_LIMIT_EXCEEDED'));

			return null;
		}

		// Rights on every named base before the first write, the same all-or-nothing rule the records
		// below answer to: one base the caller may not touch refuses the whole selection rather than
		// bringing back the part of it that happened to be allowed.
		foreach ($baseIds as $collectionId)
		{
			if (!DocumentAccessService::canManageCollectionInRecycleBin($userId, $collectionId))
			{
				$this->denyAccess();

				return null;
			}
		}

		$kind = (string)($target['kind'] ?? '');
		$targetCollectionId = $kind === self::TARGET_COLLECTION ? (int)($target['collectionId'] ?? 0) : 0;
		if ($targetCollectionId > 0 && !$this->userCanManageCollection($userId, $targetCollectionId))
		{
			$this->denyAccess();

			return null;
		}

		// The bases go first: while one of them is in the bin, its records have no destination and
		// the pre-flight below would refuse the whole selection because of them.
		[$restoredCollectionIds, $failedCollections] = $this->restoreSelectedCollections($baseIds, $userId);

		$repository = new RecycleBinRepository();
		$restoredIds = [];

		// Rows and rights for the whole selection up front: asking per entry made the check itself
		// scale with the size of the selection.
		$records = $repository->getByIds($ids);
		$acl = DocumentAccessService::bulkComputeRecycleBinAcl($userId, array_values($records));

		$known = array_values(array_filter($ids, static fn(int $entryId): bool => isset($records[$entryId])));

		// Ancestors before their own descendants, whatever order the selection arrived in: a child
		// restored while its parent is still in the bin comes back to the root with PARENT_ID = null,
		// and restoring the parent afterwards does not take it back.
		$known = $this->orderAncestorsFirst($known, $records);

		// Nothing is restored until the whole selection is known to be restorable. The rights and the
		// question of where things land are settled here, before the first write: a refusal met halfway
		// used to answer with an error while part of the selection was already out of the bin, and
		// nothing in that answer said so.
		foreach ($known as $entryId)
		{
			if (($acl[$entryId]['canRestore'] ?? false) !== true)
			{
				$this->denyAccess();

				return null;
			}
		}

		$error = $this->selectionPreflightError(
			array_map(static fn(int $entryId): RecycleBinRecord => $records[$entryId], $known),
			$targetCollectionId > 0 ? $targetCollectionId : null,
		);
		if ($error !== null)
		{
			$this->addError($error);

			return null;
		}

		foreach ($known as $entryId)
		{
			try
			{
				$result = (new RestoreDocumentFromRecycleBinCommand(
					$entryId,
					$userId,
					$targetCollectionId > 0 ? $targetCollectionId : null,
				))->run();
			}
			catch (OrphanRestoreTargetRequiredException)
			{
				// The pre-flight above said yes, so getting here means the state changed under the
				// operation - a base gone between the two loops. Same answer, and the records already
				// restored keep their new place: they went back while it was allowed.
				$this->addError($this->restoreTargetError());

				return null;
			}
			catch (SystemException)
			{
				$this->addError(new Error((string)Loc::getMessage('NOTE_DOCUMENT_TRASH_RESTORE_ERROR')));

				return null;
			}

			if (!$result->isSuccess())
			{
				$this->addErrors($result->getErrors());

				return null;
			}

			$data = $result->getData();
			$restoredIds[] = (int)($data['documentId'] ?? $records[$entryId]->getDocumentId());
		}

		return [
			'restoredIds' => $restoredIds,
			'restoredCollectionIds' => $restoredCollectionIds,
			// A base that stayed where it was, and why: past its retention, already being destroyed,
			// or held by an operation somebody else started. Reported instead of failing the call,
			// so the records that did come back are not hidden behind one base that could not.
			'failedCollections' => $failedCollections,
		];
	}

	/**
	 * Brings the named bases back whole, one by one.
	 *
	 * @param int[] $collectionIds
	 * @return array{0: int[], 1: array<int, array{collectionId: int, errorCode: string}>}
	 */
	private function restoreSelectedCollections(array $collectionIds, int $userId): array
	{
		$restored = [];
		$failed = [];

		foreach ($collectionIds as $collectionId)
		{
			try
			{
				$result = (new RestoreCollectionFromRecycleBinCommand($collectionId, $userId))->run();
			}
			catch (SystemException)
			{
				$failed[] = [
					'collectionId' => $collectionId,
					'errorCode' => RestoreAllFromRecycleBinCommand::ERROR_COLLECTION_RESTORE_FAILED,
				];

				continue;
			}

			$data = $result->getData();
			if (($data['success'] ?? false) === true)
			{
				$restored[] = $collectionId;

				continue;
			}

			$failed[] = [
				'collectionId' => $collectionId,
				'errorCode' => (string)(
					$data['errorCode'] ?? RestoreAllFromRecycleBinCommand::ERROR_COLLECTION_RESTORE_FAILED
				),
			];
		}

		return [$restored, $failed];
	}

	/**
	 * Entries of the selection ordered so a parent goes back before anything selected under it. The
	 * order is computed over the documents behind the records - depth counted inside the selection
	 * alone - and carried back to the entries the caller named.
	 *
	 * @param int[] $entryIds
	 * @param array<int, RecycleBinRecord> $records
	 * @return int[]
	 */
	private function orderAncestorsFirst(array $entryIds, array $records): array
	{
		if (count($entryIds) < 2)
		{
			return $entryIds;
		}

		$entriesByDocument = [];
		foreach ($entryIds as $entryId)
		{
			$entriesByDocument[(int)$records[$entryId]->getDocumentId()][] = $entryId;
		}

		$metaById = [];
		foreach ((new DocumentRepository())->getMetaByIds(array_keys($entriesByDocument)) as $document)
		{
			$parentId = $document->getParentId();
			$metaById[(int)$document->getId()] = [
				'parentId' => $parentId !== null ? (int)$parentId : null,
			];
		}

		$ordered = [];
		foreach ((new RestoreOrderer())->order(array_keys($entriesByDocument), $metaById) as $documentId)
		{
			foreach ($entriesByDocument[$documentId] ?? [] as $entryId)
			{
				$ordered[] = $entryId;
			}
		}

		return $ordered;
	}

	/**
	 * Whether the whole selection may go back where the caller asked, asked without writing anything.
	 *
	 * @param RecycleBinRecord[] $records
	 * @return Error|null null when the selection is free to go
	 */
	private function selectionPreflightError(array $records, ?int $targetCollectionId): ?Error
	{
		try
		{
			(new RestoreFromRecycleBinService())->assertSelectionRestorable($records, $targetCollectionId);
		}
		catch (OrphanRestoreTargetRequiredException)
		{
			return $this->restoreTargetError();
		}

		return null;
	}

	/**
	 * One refusal of the destination, told the same way wherever it is met: the caller has to answer
	 * the same question afterwards, so the code cannot differ between the pre-flight and the
	 * operation itself.
	 */
	private function restoreTargetError(): Error
	{
		return new Error(
			(string)Loc::getMessage('NOTE_DOCUMENT_TRASH_ORPHAN_TARGET_REQUIRED'),
			self::ERROR_ORPHAN_TARGET_REQUIRED,
		);
	}

	private function denyAccess(): void
	{
		$this->addError(new Error((string)(Loc::getMessage('NOTE_ACCESS_DENIED'))));
	}

	/**
	 * A base lying in the bin answers by the single right the whole base is governed by there; a
	 * live one keeps answering at VIEW, so its readers still find what was deleted out of it.
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

	private function userCanManageCollection(int $userId, int $collectionId): bool
	{
		return CollectionAccessService::userHasLevel(
			$collectionId,
			$userId,
			CollectionAccessService::LEVEL_MANAGE,
		);
	}
}
