<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Controller;

use Bitrix\Main\Command\Exception\CommandException;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\SystemException;
use Bitrix\Note\Infrastructure\Controller\ActionFilter;
use Bitrix\Note\Internal\Access\AccessController;
use Bitrix\Note\Internal\Access\ActionDictionary;
use Bitrix\Note\Internal\Access\Service\CollectionAccessService;
use Bitrix\Note\Internal\Access\Service\DocumentAccessService;
use Bitrix\Note\Internal\Exceptions\CollectionNotWritableException;
use Bitrix\Note\Internal\Model\Collection;
use Bitrix\Note\Internal\Model\FavoriteTable;
use Bitrix\Note\Internal\Repository\FavoriteRepository;
use Bitrix\Note\Internal\Service\Collection\CollectionRestoreService;
use Bitrix\Note\Public\Command\ArchiveCollectionCommand;
use Bitrix\Note\Public\Command\CreateCollectionCommand;
use Bitrix\Note\Public\Command\DeleteCollectionCommand;
use Bitrix\Note\Public\Command\MoveCollectionCommand;
use Bitrix\Note\Public\Command\UpdateCollectionCommand;
use Bitrix\Note\Public\Provider\CollectionProvider;

class CollectionController extends Controller
{
	protected function getDefaultPreFilters(): array
	{
		return array_merge(
			parent::getDefaultPreFilters(),
			[
				new ActionFilter\NoteAccess(),
			],
		);
	}

	public function createAction(string $name, int $position = 0): ?array
	{
		if (!$this->assertGlobalCollectionCreateAccess())
		{
			return null;
		}

		$userId = (int)$this->getCurrentUser()->getId();
		try
		{
			$result = (new CreateCollectionCommand($name, $userId, $position))->run();
		}
		catch (SystemException $e)
		{
			$this->addError(new Error(Loc::getMessage('NOTE_COLLECTION_CREATE_ERROR')));

			return null;
		}

		$collection = $result->getData()['collection'] ?? null;
		if (!$collection instanceof Collection)
		{
			return null;
		}

		return $this->mapCollection($collection);
	}

	public function listAction(int $limit = 50, ?int $afterPosition = null, ?int $afterId = null): array
	{
		$provider = new CollectionProvider();

		$afterCursor = null;
		if ($afterPosition !== null && $afterId !== null)
		{
			$afterCursor = ['position' => $afterPosition, 'id' => $afterId];
		}

		$batch = $provider->getAccessibleBatch($limit, $afterCursor);

		$items = $provider->mapCollectionsWithAccess(
			$batch['entities'],
			$batch['effectiveMap'],
			$batch['policyMap'],
			$batch['isAdmin'],
		);

		$this->registerSidebarPullWatches($items);

		return [
			// Additive star flag: one batch read per page, never one read per row.
			'items' => $this->attachFavoriteFlag($items),
			'nextCursor' => $batch['nextCursor'],
		];
	}

	private function attachFavoriteFlag(array $items): array
	{
		$userId = (int)$this->getCurrentUser()->getId();
		$ids = array_map(static fn(array $item): int => (int)($item['id'] ?? 0), $items);
		$favoriteIds = array_flip(
			(new FavoriteRepository())->findFavoriteEntityIds($userId, FavoriteTable::ENTITY_TYPE_COLLECTION, $ids),
		);

		foreach ($items as &$item)
		{
			$item['isFavorite'] = isset($favoriteIds[(int)($item['id'] ?? 0)]);
		}
		unset($item);

		return $items;
	}

	private function registerSidebarPullWatches(array $items): void
	{
		$userId = (int)\Bitrix\Main\Engine\CurrentUser::get()->getId();
		if ($userId <= 0)
		{
			return;
		}

		\CPullWatch::Add($userId, 'NOTE_GLOBAL');
		foreach ($items as $item)
		{
			$collectionId = (int)($item['id'] ?? 0);
			if ($collectionId <= 0)
			{
				continue;
			}
			\CPullWatch::Add($userId, 'NOTE_COLLECTION_' . $collectionId);
			\CPullWatch::Add($userId, 'NOTE_COLLECTION_' . $collectionId . '_ACL');
		}
	}

	public function listManageableShortAction(int $limit = 2): array
	{
		$normalizedLimit = $limit > 0 ? min($limit, 50) : 2;

		return (new CollectionProvider())->getManageableShort($normalizedLimit);
	}

	public function moveAction(
		int $id,
		?int $position = null,
		?int $targetId = null,
		?string $placement = null,
	): ?array
	{
		if (!$this->assertGlobalCollectionCreateAccess())
		{
			return null;
		}

		// The order is portal-wide and guarded by the global right, but only bases the person sees
		// may take part in it - the one being dragged and the neighbour it lands next to.
		if (!$this->assertCollectionViewAccess($id))
		{
			return null;
		}

		if ($targetId !== null && !$this->assertCollectionViewAccess($targetId))
		{
			return null;
		}

		if (!$this->assertCollectionIsLive($id))
		{
			return null;
		}

		$userId = (int)$this->getCurrentUser()->getId();
		try
		{
			$command = $targetId === null || $placement === null
				? new MoveCollectionCommand($id, $position, $userId)
				: MoveCollectionCommand::nextTo($id, $targetId, $placement, $userId)
			;
			$result = $command->run();
		}
		catch (SystemException $e)
		{
			$this->addError(new Error(Loc::getMessage('NOTE_COLLECTION_REORDER_ERROR')));

			return null;
		}

		return $result->getData();
	}

	public function updateAction(int $id, string $name): ?array
	{
		if (!$this->assertCollectionManageAccess($id))
		{
			return null;
		}

		if (!$this->assertCollectionIsLive($id))
		{
			return null;
		}

		$userId = (int)$this->getCurrentUser()->getId();
		try
		{
			$result = (new UpdateCollectionCommand($id, $name, $userId))->run();
		}
		catch (CommandException $e)
		{
			// The state of the base is a server-side ban, so a direct call by id gets a named
			// refusal and not a generic failure.
			if ($e->getPrevious() instanceof CollectionNotWritableException)
			{
				$this->addError(new Error(
					(string)Loc::getMessage('NOTE_COLLECTION_UPDATE_READ_ONLY'),
					'NOTE_COLLECTION_READ_ONLY',
				));

				return null;
			}

			$this->addError(new Error(Loc::getMessage('NOTE_COLLECTION_UPDATE_ERROR')));

			return null;
		}
		catch (CollectionNotWritableException)
		{
			$this->addError(new Error(
				(string)Loc::getMessage('NOTE_COLLECTION_UPDATE_READ_ONLY'),
				'NOTE_COLLECTION_READ_ONLY',
			));

			return null;
		}
		catch (SystemException $e)
		{
			$this->addError(new Error(Loc::getMessage('NOTE_COLLECTION_UPDATE_ERROR')));

			return null;
		}
		$collection = $result->getData()['collection'] ?? null;
		if (!$collection instanceof Collection)
		{
			return null;
		}

		return $this->mapCollection($collection);
	}

	public function deleteAction(int $id): bool
	{
		if (!$this->assertCollectionModerateAccess($id))
		{
			return false;
		}

		$result = (new DeleteCollectionCommand($id, (int)$this->getCurrentUser()->getId()))->run();
		$data = $result->getData();
		if ($data['success'] ?? false)
		{
			return true;
		}

		if (($data['errorCode'] ?? null) === 'COLLECTION_UNDER_IMPORT')
		{
			$this->addError(new Error(
				Loc::getMessage('NOTE_COLLECTION_DELETE_LOCKED_BY_IMPORT'),
				'COLLECTION_UNDER_IMPORT',
			));
		}

		return false;
	}

	public function archiveAction(int $id): bool
	{
		if (!$this->assertCollectionModerateAccess($id))
		{
			return false;
		}

		if (!$this->assertCollectionIsLive($id))
		{
			return false;
		}

		$userId = (int)$this->getCurrentUser()->getId();
		try
		{
			$result = (new ArchiveCollectionCommand($id, $userId))->run();
		}
		catch (SystemException $e)
		{
			$this->addError(new Error(Loc::getMessage('NOTE_COLLECTION_ARCHIVE_ERROR')));

			return false;
		}

		return (bool)($result->getData()['success'] ?? false);
	}

	/**
	 * The mirror of archiveAction, which had no pair: the header of an archived section offers
	 * "open" and "unarchive", and the second had nothing to call. The lift itself is the one the
	 * unarchiving of a document already goes through - a second path to the same state is exactly
	 * what would eventually diverge.
	 *
	 * A base that was not archived is a success without an action, exactly as a repeated delete is.
	 */
	public function unarchiveAction(int $id): bool
	{
		if (!$this->assertCollectionModerateAccess($id))
		{
			return false;
		}

		// A base can be archived and trashed at once. Lifting it out of the archive while it lies in
		// the bin would hand back a base nobody can reach: the way out of the bin is its own action.
		if (!$this->assertCollectionIsLive($id))
		{
			return false;
		}

		$userId = (int)$this->getCurrentUser()->getId();
		try
		{
			$result = (new CollectionRestoreService())->restore($id, $userId);
		}
		catch (SystemException)
		{
			$this->addError(new Error((string)Loc::getMessage('NOTE_COLLECTION_UNARCHIVE_ERROR')));

			return false;
		}

		$collection = $result->getData()['collection'] ?? null;
		if (!$collection instanceof Collection)
		{
			$this->addError(new Error((string)Loc::getMessage('NOTE_COLLECTION_UNARCHIVE_ERROR')));

			return false;
		}

		return true;
	}

	public function getPermissionsAction(int $id): array
	{
		if (!$this->assertCollectionPermissionsAccess($id))
		{
			return [];
		}

		if (!$this->assertCollectionIsLive($id))
		{
			return [];
		}

		return [
			'collectionId' => $id,
			'policyLevel' => CollectionAccessService::levelToCode(
				CollectionAccessService::getCollectionPolicyLevel($id)
			),
			'permissions' => CollectionAccessService::getCollectionPermissions($id),
		];
	}

	public function getMyAccessAction(int $id): array
	{
		$snapshot = CollectionAccessService::getCurrentUserAccessSnapshot($id);
		$effective = (int)$snapshot['effective'];
		// Hide policy from callers without VIEW so getMyAccess doesn't leak the policy
		// label of collections the user can't see anyway.
		$policy = $effective >= CollectionAccessService::LEVEL_VIEW
			? (int)$snapshot['policy']
			: CollectionAccessService::LEVEL_NONE;

		return [
			'collectionId' => $id,
			'level' => CollectionAccessService::levelToCode($effective),
			'policyLevel' => CollectionAccessService::levelToCode($policy),
			'canEditCollection' => $effective >= CollectionAccessService::LEVEL_MANAGE,
			'canManagePermissions' => $effective >= CollectionAccessService::LEVEL_MODERATE,
		];
	}

	public function savePermissionsAction(int $id, array $permissions = [], string $policyLevel = 'none'): ?bool
	{
		if (!$this->assertCollectionPermissionsAccess($id))
		{
			return null;
		}

		if (!$this->assertCollectionIsLive($id))
		{
			return null;
		}

		$result = CollectionAccessService::replaceCollectionPermissions(
			$id,
			$permissions,
			(int)$this->getCurrentUser()->getId(),
			$policyLevel,
		);

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return true;
	}

	private function mapCollection(Collection $collection): array
	{
		return (new CollectionProvider())->mapCollectionForCurrentUser($collection);
	}

	private function assertGlobalCollectionCreateAccess(): bool
	{
		if (AccessController::getCurrent()->check(ActionDictionary::ACTION_NOTE_CREATE_COLLECTIONS))
		{
			return true;
		}

		$this->denyAccess();

		return false;
	}

	private function assertCollectionViewAccess(int $collectionId): bool
	{
		if (CollectionAccessService::currentUserHasLevel($collectionId, CollectionAccessService::LEVEL_VIEW))
		{
			return true;
		}

		$this->denyAccess();

		return false;
	}

	private function assertCollectionManageAccess(int $collectionId): bool
	{
		if ($this->canManageCollection($collectionId))
		{
			return true;
		}

		$this->denyAccess();

		return false;
	}

	private function assertCollectionModerateAccess(int $collectionId): bool
	{
		if ($this->canModerateCollection($collectionId))
		{
			return true;
		}

		$this->denyAccess();

		return false;
	}

	private function assertCollectionPermissionsAccess(int $collectionId): bool
	{
		if ($this->canModerateCollection($collectionId))
		{
			return true;
		}

		$this->denyAccess();

		return false;
	}

	/**
	 * Every action here belongs to the screen of a live knowledge base. A base in the recycle bin keeps
	 * its access rows, so the level checks above go on passing for the people who moderate it - and
	 * without this they would let a deleted base be renamed, reordered, archived or given a new access
	 * list from a screen that is simply out of date. What the bin allows is decided in its own
	 * controller, by its own right.
	 */
	private function assertCollectionIsLive(int $collectionId): bool
	{
		$state = DocumentAccessService::collectionState($collectionId);
		// Only the bin is answered here. A base with no row at all is left to the action below, which
		// already has its own answer for a name that points at nothing.
		if ($state === null || !$state['trashed'])
		{
			return true;
		}

		$this->addError(new Error(
			(string)Loc::getMessage('NOTE_COLLECTION_IN_RECYCLE_BIN'),
			'NOTE_COLLECTION_IN_RECYCLE_BIN',
		));

		return false;
	}

	private function canManageCollection(int $collectionId): bool
	{
		return CollectionAccessService::currentUserHasLevel($collectionId, CollectionAccessService::LEVEL_MANAGE);
	}

	private function canModerateCollection(int $collectionId): bool
	{
		return CollectionAccessService::currentUserHasLevel($collectionId, CollectionAccessService::LEVEL_MODERATE);
	}

	private function denyAccess(): void
	{
		$this->addError(new Error(Loc::getMessage('NOTE_ACCESS_DENIED')));
	}
}
