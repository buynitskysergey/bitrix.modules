<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Integration\UI\EntitySelector;

use Bitrix\Bizproc\Public\Provider\Params\StorageType\StorageTypeFilter;
use Bitrix\Bizproc\Public\Provider\Params\StorageType\StorageTypeSelect;
use Bitrix\Bizproc\Public\Provider\Params\StorageType\StorageTypeSort;
use Bitrix\Bizproc\Public\Provider\StorageTypeProvider;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Provider\Params\GridParams;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\UI\EntitySelector\BaseProvider;
use Bitrix\UI\EntitySelector\Dialog;
use Bitrix\UI\EntitySelector\Item;
use Bitrix\UI\EntitySelector\SearchQuery;
use Bitrix\Main\Localization\Loc;

class StorageProvider extends BaseProvider
{
	public const ENTITY_ID = 'bizproc-storage';

	private const ITEMS_LIMIT = 50;

	public function __construct(array $options)
	{
		parent::__construct();

		$this->options = $options;
	}

	final public function isAvailable(): bool
	{
		return $this->getCurrentUserId() > 0;
	}

	final public function fillDialog(Dialog $dialog): void
	{
		$items = $this->makeItems();

		array_walk(
			$items,
			static function (Item $item) use ($dialog) {
				$dialog->addRecentItem($item);
			}
		);
	}

	final public function getItems(array $ids): array
	{
		return $this->makeItemsByIds($ids);
	}

	final public function getSelectedItems(array $ids): array
	{
		return $this->getItems($ids);
	}

	final public function doSearch(SearchQuery $searchQuery, Dialog $dialog): void
	{
		$search = trim($searchQuery->getQuery());
		if (\CBPHelper::isEmptyValue($search))
		{
			return;
		}

		$searchQuery->setCacheable(false); // required for dynamicSearchMatchMode: 'all'

		$items = $this->makeItems(new StorageTypeFilter(['TITLE' => $search]));
		if ($items)
		{
			$dialog->addItems($items);
		}
	}

	private function makeItems(?StorageTypeFilter $filter = null): array
	{
		$provider = new StorageTypeProvider();

		$gridParams = new GridParams(
			pager: new Pager(limit: self::ITEMS_LIMIT),
			filter: $filter,
			sort: new StorageTypeSort(['ID' => 'DESC']),
			select: new StorageTypeSelect(['ID', 'TITLE']),
		);

		$collection = $provider->getList($gridParams);

		$items = [];
		foreach ($collection as $storageItem)
		{
			$id = $storageItem->getId();
			$title = $storageItem->getTitle();

			$items[] = $this->makeItem($id, $title);
		}

		return $items;
	}

	private function makeItemsByIds(array $ids): array
	{
		$ids = array_filter(array_map('intval', $ids));
		if (empty($ids))
		{
			return [];
		}

		$provider = new StorageTypeProvider();
		$collection = $provider->getStoragesByFilter(['@ID' => $ids], ['ID', 'TITLE']);

		$items = [];
		foreach ($collection as $storageItem)
		{
			$id = $storageItem->getId();
			$title = $storageItem->getTitle();

			$items[] = $this->makeItem($id, $title);
		}

		return $items;
	}

	private function makeItem(int $id, string $title): Item
	{
		return new Item([
			'id' => $id,
			'entityId' => static::ENTITY_ID,
			'title' => $title,
			'linkTitle' => Loc::getMessage('BIZPROC_ENTITY_SELECTOR_STORAGE_LINK') ?? '',
			'link' => "/bitrix/components/bitrix/bizproc.storage.item.list/?storageId={$id}",
		]);
	}

	protected function getCurrentUserId(): int
	{
		return (int)(CurrentUser::get()->getId());
	}
}
