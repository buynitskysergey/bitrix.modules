<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Integration\UI\EntitySelector;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Localization\Loc;
use Bitrix\Note\Internal\Access\AccessController;
use Bitrix\Note\Internal\Access\ActionDictionary;
use Bitrix\Note\Internal\Integration\Disk\DiskFolderGateway;
use Bitrix\Note\Internal\Integration\Disk\DiskFolderItem;
use Bitrix\Note\Internal\Service\License\LicenseService;
use Bitrix\UI\EntitySelector\BaseProvider;
use Bitrix\UI\EntitySelector\Dialog;
use Bitrix\UI\EntitySelector\Item;
use Bitrix\UI\EntitySelector\SearchQuery;
use Bitrix\UI\EntitySelector\Tab;

/**
 * The "Folders" tab of the mention dialog: top-level folders of the viewer's own Disk to start from,
 * and Disk's own search for everything else.
 *
 * Nothing here knows Disk — every call goes through {@see DiskFolderGateway}, which is also what
 * keeps the list and a resolved chip agreeing on which folders exist for whom. Workgroups are never
 * enumerated: rights filtering inside Disk's own queries already reaches group and collab Disks.
 *
 * Not final: tests subclass to override the seams below.
 */
class DiskFolderProvider extends BaseProvider
{
	public const ENTITY_ID = 'note-disk-folder';

	private const DISK_FOLDER_TAB_ID = 'note-disk-folder-tab';
	private const LIST_LIMIT = 50;
	private const SEARCH_LIMIT = 50;

	private ?DiskFolderGateway $folderGateway = null;

	public function __construct(array $options = [])
	{
		parent::__construct();

		$this->options = $options;
	}

	public function isAvailable(): bool
	{
		// Tariff/tool gate before ACL: same denial as an ACL failure.
		if ($this->createLicenseService()->isAccessBlocked())
		{
			return false;
		}

		// No Disk on the portal is not an error, it is "there is nothing to offer": without the tab
		// the dialog simply has one entity fewer.
		if (!$this->folderGateway()->isAvailable())
		{
			return false;
		}

		$userId = (int)CurrentUser::get()->getId();
		if ($userId <= 0)
		{
			return false;
		}

		// Gate the global selector behind the Notes tool ACL — a user without note_access
		// must not enumerate folder names through the entity-selector.
		return AccessController::getCurrent()->check(ActionDictionary::ACTION_NOTE_ACCESS);
	}

	protected function createLicenseService(): LicenseService
	{
		return new LicenseService();
	}

	protected function createFolderGateway(): DiskFolderGateway
	{
		return new DiskFolderGateway();
	}

	public function fillDialog(Dialog $dialog): void
	{
		// The tab goes in before the items: a person whose Disk is empty still has to reach the search.
		$dialog->addTab($this->makeTab());

		$dialog->loadPreselectedItems();

		$userId = (int)CurrentUser::get()->getId();
		if ($userId <= 0)
		{
			return;
		}

		// A flat list of the viewer's own top-level folders by name. Nothing is expanded and nothing is
		// ranked by recency: a folder mention has no history to rank on.
		foreach ($this->folderGateway()->listTopLevelPersonalFolders($userId, self::LIST_LIMIT) as $folder)
		{
			$dialog->addRecentItem($this->makeItem($folder));
		}
	}

	public function doSearch(SearchQuery $searchQuery, Dialog $dialog): void
	{
		$dialog->addTab($this->makeTab());

		// Before any early return below: the client refines a cacheable query on its own, so a query
		// cached here keeps every longer one from ever reaching the server. Required by
		// dynamicSearchMatchMode: 'all' as well — that mode keeps loaded items instead of narrowing them.
		$searchQuery->setCacheable(false);

		$queryText = trim($searchQuery->getQuery());
		if (!$this->folderGateway()->isSearchable($queryText))
		{
			return;
		}

		$userId = (int)CurrentUser::get()->getId();
		if ($userId <= 0)
		{
			return;
		}

		foreach ($this->folderGateway()->searchFolders($userId, $queryText, self::SEARCH_LIMIT) as $folder)
		{
			$dialog->addItem($this->makeItem($folder));
		}
	}

	public function getItems(array $ids): array
	{
		$ids = array_values(array_filter(array_map('intval', $ids), static fn(int $id) => $id > 0));
		if (empty($ids))
		{
			return [];
		}

		$userId = (int)CurrentUser::get()->getId();
		if ($userId <= 0)
		{
			return [];
		}

		// The same batch the chip is resolved by, so a preselected id cannot smuggle in a folder the
		// list would never have offered.
		$items = [];
		foreach ($this->folderGateway()->resolveBatch($ids, $userId) as $folder)
		{
			$items[] = $this->makeItem($folder);
		}

		return $items;
	}

	public function getPreselectedItems(array $ids): array
	{
		return $this->getItems($ids);
	}

	private function folderGateway(): DiskFolderGateway
	{
		return $this->folderGateway ??= $this->createFolderGateway();
	}

	private function makeItem(DiskFolderItem $folder): Item
	{
		return new Item([
			'id' => $folder->id,
			'entityId' => self::ENTITY_ID,
			'tabs' => [self::DISK_FOLDER_TAB_ID],
			'title' => $folder->name,
			'supertitle' => Loc::getMessage('NOTE_ENTITY_SELECTOR_DISK_FOLDER_ITEM_SUPERTITLE'),
			// Folder names repeat across Disks, so the storage tells three "Документы" apart. It comes
			// with the folder itself — never a query per item.
			'subtitle' => $folder->storageName,
			'avatarOptions' => [
				// DS tokens; both bgColor and iconColor are written as inline styles on elements
				// inside the popup, so they resolve against its design-system context (light/dark).
				'icon' => 'o-folder',
				'iconColor' => 'var(--ui-color-design-filled-content-icon)',
				'bgColor' => 'var(--ui-color-design-filled-bg)',
			],
		]);
	}

	private function makeTab(): Tab
	{
		return new Tab([
			'id' => self::DISK_FOLDER_TAB_ID,
			'title' => Loc::getMessage('NOTE_ENTITY_SELECTOR_DISK_FOLDER_TAB_TITLE'),
			'icon' => ['default' => 'o-folder'],
		]);
	}
}
