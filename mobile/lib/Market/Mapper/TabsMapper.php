<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Market\Mapper;

use Bitrix\Main\Localization\Loc;
use Bitrix\Mobile\Market\Dto\AppListTabs;
use Bitrix\Mobile\Market\Dto\Tab;
use Bitrix\Mobile\Market\InstalledFilter;
use Bitrix\Mobile\Market\Navigation;
use Bitrix\Mobile\Market\TabsMode;

final class TabsMapper
{
	public function mapCategoryTabs(array $result, string $developerTag = ''): ?AppListTabs
	{
		$categoryTabs = (is_array($result['FILTER_CATEGORIES'] ?? null) ? $result['FILTER_CATEGORIES'] : []);
		if (!empty($categoryTabs))
		{
			return $this->mapCategoryNavigationTabs($categoryTabs);
		}

		$developerTagTabs = (is_array($result['FILTER_TAGS'] ?? null) ? $result['FILTER_TAGS'] : []);
		if (!empty($developerTagTabs))
		{
			return $this->mapDeveloperTagTabs($developerTagTabs, $developerTag);
		}

		return null;
	}

	public function mapInstalledTabs(string $selectedFilter = ''): AppListTabs
	{
		$hasUpdatesSelected = ($selectedFilter === InstalledFilter::UPDATES);

		return new AppListTabs(
			mode: TabsMode::Switch,
			initialTabId: $hasUpdatesSelected ? InstalledFilter::UPDATES : '__all__',
			items: [
				new Tab(
					id: '__all__',
					title: (string)Loc::getMessage('MOBILE_MARKET_LIST_TAB_ALL'),
					active: !$hasUpdatesSelected,
					payload: [
						'installedFilter' => '',
					],
				),
				new Tab(
					id: InstalledFilter::UPDATES,
					title: (string)Loc::getMessage('MOBILE_MARKET_INSTALLED_TAB_UPDATES'),
					active: $hasUpdatesSelected,
					payload: [
						'installedFilter' => InstalledFilter::UPDATES,
					],
				),
			],
		);
	}

	private function mapCategoryNavigationTabs(array $tabs): ?AppListTabs
	{
		if (empty($tabs))
		{
			return null;
		}

		$items = [
			new Tab(
				id: '__all__',
				title: (string)Loc::getMessage('MOBILE_MARKET_LIST_TAB_ALL'),
				active: true,
			),
		];

		foreach ($tabs as $tab)
		{
			$code = (string)($tab['value'] ?? '');
			$title = (string)($tab['name'] ?? '');

			if ($code === '' || $title === '')
			{
				continue;
			}

			$items[] = new Tab(
				id: $code,
				title: $title,
				url: Navigation::getNativeCategoryUrl($code),
			);
		}

		if (empty($items))
		{
			return null;
		}

		return new AppListTabs(
			mode: TabsMode::Navigate,
			items: $items,
		);
	}

	private function mapDeveloperTagTabs(array $tabs, string $selectedTag = ''): ?AppListTabs
	{
		if (empty($tabs))
		{
			return null;
		}

		$initialTabId = '__all__';
		$items = [
			new Tab(
				id: '__all__',
				title: (string)Loc::getMessage('MOBILE_MARKET_LIST_TAB_ALL'),
				active: ($selectedTag === ''),
				payload: [
					'developerTag' => '',
				],
			),
		];

		foreach ($tabs as $tab)
		{
			$code = (string)($tab['value'] ?? '');
			$title = (string)($tab['name'] ?? '');

			if ($code === '' || $title === '')
			{
				continue;
			}

			$isSelected = ($selectedTag !== '' && $code === $selectedTag);
			if ($isSelected)
			{
				$initialTabId = $code;
			}

			$items[] = new Tab(
				id: $code,
				title: $title,
				active: $isSelected,
				payload: [
					'developerTag' => $code,
				],
			);
		}

		return new AppListTabs(
			mode: TabsMode::Switch,
			initialTabId: $initialTabId,
			items: $items,
		);
	}
}
