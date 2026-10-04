<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Controller;

use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\SystemException;
use Bitrix\Note\Infrastructure\Controller\ActionFilter;
use Bitrix\Note\Internal\Access\AccessController;
use Bitrix\Note\Internal\Access\ActionDictionary;
use Bitrix\Note\Internal\Access\Service\CollectionAccessService;
use Bitrix\Note\Internal\Model\Collection;
use Bitrix\Note\Internal\Model\FavoriteTable;
use Bitrix\Note\Internal\Repository\FavoriteRepository;
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
			// [TPL-01] Additive star flag: one batch read per page, never one read per row.
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

	public function moveAction(int $id, ?int $position = null): ?array
	{
		if (!$this->assertGlobalCollectionCreateAccess())
		{
			return null;
		}

		$userId = (int)$this->getCurrentUser()->getId();
		try
		{
			$result = (new MoveCollectionCommand($id, $position, $userId))->run();
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

		$userId = (int)$this->getCurrentUser()->getId();
		try
		{
			$result = (new UpdateCollectionCommand($id, $name, $userId))->run();
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

	public function getPermissionsAction(int $id): array
	{
		if (!$this->assertCollectionPermissionsAccess($id))
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
