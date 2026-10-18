<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Integration\Disk;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Driver;
use Bitrix\Disk\Folder;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Disk\ProxyType;
use Bitrix\Disk\Search\FullTextBuilder;
use Bitrix\Disk\Search\StorageFileFinder;
use Bitrix\Disk\Search\StorageFileFinderOptions;
use Bitrix\Disk\Security\DiskSecurityContext;
use Bitrix\Disk\Storage;
use Bitrix\Main\Loader;
use Bitrix\Main\Search\Content;

/**
 * The module's only door to Disk folders: listing, searching and resolving them by id, plus the
 * address a folder is reached at.
 *
 * All three go through one set of selection conditions and one way of building the viewer's context,
 * so what the picker offers is exactly what a chip later resolves. Rights are never our own answer —
 * Disk filters every query by them, and a per-object check would cost a query per mention.
 */
class DiskFolderGateway
{
	public function isAvailable(): bool
	{
		return Loader::includeModule('disk');
	}

	/**
	 * Folders sitting directly in the viewer's own Disk, by name.
	 *
	 * @return DiskFolderItem[]
	 */
	public function listTopLevelPersonalFolders(int $userId, int $limit): array
	{
		if (!$this->isAvailable())
		{
			return [];
		}

		$root = Driver::getInstance()->getStorageByUserId($userId)?->getRootObject();
		if ($root === null)
		{
			return [];
		}

		$children = $root->getChildren(
			$this->securityContext($userId),
			[
				'filter' => $this->folderFilter(),
				'with' => ['STORAGE'],
				'order' => ['NAME' => 'ASC'],
				'limit' => $limit,
			],
		);

		return array_values($this->toItems($children, $userId));
	}

	/**
	 * Whether Disk's full-text search will look at this query at all.
	 *
	 * The verdict is the finder's own, never a literal threshold: the minimum token size comes from
	 * the portal's connection settings and is measured on the prepared string rather than on what was
	 * typed, so any number written here would drift from the search it is supposed to predict.
	 */
	public function isSearchable(string $query): bool
	{
		if (!$this->isAvailable())
		{
			return false;
		}

		$prepared = (string)FullTextBuilder::create()->addText($query)->getSearchValue();

		return $prepared !== '' && Content::canUseFulltextSearch($prepared);
	}

	/**
	 * Folders matching a text query anywhere the viewer has rights: nested, common Disk, group and
	 * collab Disks alike.
	 *
	 * @return DiskFolderItem[]
	 */
	public function searchFolders(int $userId, string $query, int $limit): array
	{
		if (!$this->isAvailable())
		{
			return [];
		}

		// Options mode: the finder ignores its own limit, order and entityTypes there — limit comes from
		// the options, order is fixed inside the finder, storage types come from proxyTypes — and
		// objectTypes defaults to files, so folders have to be asked for.
		$options = new StorageFileFinderOptions(
			limit: $limit,
			offset: 0,
			objectTypes: [ObjectTable::TYPE_FOLDER],
			proxyTypes: $this->storageProxyTypes(),
			additionalFilter: $this->folderFilterForFinder(),
		);

		$found = (new StorageFileFinder($userId, options: $options))->findModelsByText($query);

		return array_values($this->toItems($found, $userId));
	}

	/**
	 * One query for the whole batch, keyed by the requested id.
	 *
	 * @param int[] $ids
	 * @return array<int, DiskFolderItem> requested id => folder; a missing key means "not available"
	 */
	public function resolveBatch(array $ids, int $userId): array
	{
		if (!$this->isAvailable())
		{
			return [];
		}

		return $this->toItems($this->loadFolders($ids, $userId), $userId);
	}

	/**
	 * Folders a person made, told apart from the ones a module made: Disk's schema has no system
	 * flag, but every service folder fills either CODE or XML_ID, and a symlink to a shared folder
	 * leaves both empty just like an ordinary one.
	 *
	 * The conditions go into the query. Filtering the result instead would break limits and let a
	 * chip resolve a folder the list would never have offered.
	 */
	private function folderFilter(): array
	{
		return [
			'=TYPE' => ObjectTable::TYPE_FOLDER,
			'@STORAGE.ENTITY_TYPE' => $this->storageProxyTypes(),
			...$this->folderFilterForFinder(),
		];
	}

	/**
	 * The part of {@see folderFilter()} the finder does not already apply itself: it sets the object
	 * type from its options and the storage types from proxyTypes.
	 *
	 * Both OR branches are nested arrays on purpose. Written flat, the repeated key collapses to the
	 * last one and the query silently loses every folder whose CODE is NULL — nearly all of them.
	 */
	private function folderFilterForFinder(): array
	{
		return [
			'!PARENT_ID' => null,
			['LOGIC' => 'OR', ['=CODE' => null], ['=CODE' => '']],
			['LOGIC' => 'OR', ['=XML_ID' => null], ['=XML_ID' => '']],
		];
	}

	private function storageProxyTypes(): array
	{
		return [ProxyType\User::class, ProxyType\Group::class, ProxyType\Common::class];
	}

	/**
	 * Built directly, never through Storage::getSecurityContext(): that one hands a portal
	 * administrator a context that allows everything, and the start list would then disagree with
	 * both search and chip resolution. It also skips the unified-link switch, so a folder shared by
	 * link but without rights stays unavailable.
	 */
	private function securityContext(int $userId): DiskSecurityContext
	{
		return new DiskSecurityContext($userId);
	}

	/**
	 * @param int[] $ids
	 * @return array<int, BaseObject> id => folder
	 */
	private function loadFolders(array $ids, int $userId): array
	{
		$ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
		if ($ids === [])
		{
			return [];
		}

		$parameters = Driver::getInstance()->getRightsManager()->addRightsCheck(
			$this->securityContext($userId),
			[
				'select' => ['*'],
				'filter' => $this->folderFilter() + [
					'@ID' => $ids,
					'=DELETED_TYPE' => ObjectTable::DELETED_TYPE_NONE,
				],
				'with' => ['STORAGE'],
			],
			['ID', 'CREATED_BY'],
		);

		$folders = [];
		foreach (Folder::getModelList($parameters) as $folder)
		{
			$folders[(int)$folder->getId()] = $folder;
		}

		return $folders;
	}

	/**
	 * @param BaseObject[] $rows
	 * @return array<int, DiskFolderItem> row id => item
	 */
	private function toItems(array $rows, int $userId): array
	{
		$originals = $this->loadOriginalsOfLinks($rows, $userId);

		$items = [];
		foreach ($rows as $row)
		{
			$rowId = (int)$row->getId();
			$originalId = (int)$row->getRealObjectId();
			// Name, address and storage all come from the original: a symlink may carry a name of its
			// own, and the folder would then be offered under one name and resolved under another.
			$original = $originalId === $rowId ? $row : ($originals[$originalId] ?? null);
			if ($original === null)
			{
				continue;
			}

			$items[$rowId] = new DiskFolderItem(
				id: $originalId,
				name: (string)$original->getName(),
				url: Driver::getInstance()->getUrlManager()->getUnifiedLink($original),
				storageName: $this->storageName($original),
			);
		}

		return $items;
	}

	/**
	 * Originals behind the symlinks in $rows, read through the very query a chip is resolved by: a
	 * shared folder therefore gets the same verdict in the list and in the chip.
	 *
	 * @param BaseObject[] $rows
	 * @return array<int, BaseObject>
	 */
	private function loadOriginalsOfLinks(array $rows, int $userId): array
	{
		$originalIds = [];
		foreach ($rows as $row)
		{
			if ((int)$row->getRealObjectId() !== (int)$row->getId())
			{
				$originalIds[] = (int)$row->getRealObjectId();
			}
		}

		return $originalIds === [] ? [] : $this->loadFolders($originalIds, $userId);
	}

	/**
	 * A group Disk is told apart by the group it belongs to, so its own name is the useful one; for a
	 * personal or common Disk the proxy type says "My Drive" or "Common Drive" instead.
	 */
	private function storageName(BaseObject $folder): string
	{
		$storage = $folder->getStorage();
		if (!$storage instanceof Storage)
		{
			return '';
		}

		if ($storage->getEntityType() === ProxyType\Group::class)
		{
			return (string)$storage->getName();
		}

		// Disk exposes no title method taking a user id, so this caption follows the current portal user
		// rather than the $userId the rest of the gateway is parameterised by. Every caller in the
		// product passes the current user anyway.
		return (string)$storage->getProxyType()->getTitleForCurrentUser();
	}
}
