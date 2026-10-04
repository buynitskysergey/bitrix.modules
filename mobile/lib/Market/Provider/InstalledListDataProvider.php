<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Market\Provider;

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\Mobile\Internal\Integration\Market\MarketService;
use Bitrix\Mobile\Market\AppListType;
use Bitrix\Mobile\Market\Dto\AppList;
use Bitrix\Mobile\Market\InstalledFilter;
use Bitrix\Mobile\Market\Mapper\ListItemMapper;
use Bitrix\Mobile\Market\Mapper\TabsMapper;
use Bitrix\Mobile\Market\Navigation;

final class InstalledListDataProvider
{
	private readonly MarketService $marketService;

	public function __construct(?MarketService $marketService = null)
	{
		$this->marketService = $marketService ?? new MarketService();
	}

	public function getData(
		int $page = 1,
		string $installedFilter = '',
		?PageNavigation $pageNavigation = null,
	): AppList
	{
		$page = max(1, ($pageNavigation?->getCurrentPage() ?? $page));
		$installedFilter = InstalledFilter::normalize($installedFilter);

		$data = $this->getDefaultData($installedFilter, $this->prepareAccessDeniedUnavailableState());

		if (!$this->marketService->isMarketAvailable())
		{
			return $this->getDefaultData($installedFilter, $this->prepareMarketUnavailableState());
		}

		if (!$this->marketService->canViewInstalledList())
		{
			return $data;
		}

		$result = $this->marketService->getInstalledListInfo($page, $installedFilter);

		return new AppList(
			isAvailable: true,
			listType: AppListType::Installed,
			installedFilter: $installedFilter,
			title: (string)($result['TITLE'] ?? $data->title),
			tabs: (new TabsMapper())->mapInstalledTabs($installedFilter),
			items: (new ListItemMapper($this->marketService))->mapCollection(
				$result['APPS'] ?? [],
				Navigation::INSTALLED_LIST_FROM,
			),
			pagination: AppList::createPagination(
				currentPage: (int)($result['CUR_PAGE'] ?? 1),
				pages: (int)($result['PAGES'] ?? 1),
			),
			emptyState: $this->prepareEmptyState($installedFilter),
			unavailableState: $this->prepareAccessDeniedUnavailableState(),
		);
	}

	private function getDefaultData(string $installedFilter, array $unavailableState): AppList
	{
		return new AppList(
			listType: AppListType::Installed,
			installedFilter: $installedFilter,
			title: (string)Loc::getMessage('MOBILE_MARKET_INSTALLED_TITLE'),
			tabs: (new TabsMapper())->mapInstalledTabs($installedFilter),
			emptyState: $this->prepareEmptyState($installedFilter),
			unavailableState: $unavailableState,
		);
	}

	private function prepareAccessDeniedUnavailableState(): array
	{
		return [
			'title' => (string)Loc::getMessage('MOBILE_MARKET_INSTALLED_UNAVAILABLE_TITLE'),
			'description' => (string)Loc::getMessage('MOBILE_MARKET_INSTALLED_UNAVAILABLE_DESCRIPTION'),
		];
	}

	private function prepareMarketUnavailableState(): array
	{
		return [
			'title' => (string)Loc::getMessage('MOBILE_MARKET_INSTALLED_MARKET_UNAVAILABLE_TITLE'),
			'description' => (string)Loc::getMessage('MOBILE_MARKET_INSTALLED_MARKET_UNAVAILABLE_DESCRIPTION'),
		];
	}

	private function prepareEmptyState(string $installedFilter = ''): array
	{
		$isUpdatesFilter = ($installedFilter === InstalledFilter::UPDATES);

		return [
			'title' => (string)Loc::getMessage(
				$isUpdatesFilter
					? 'MOBILE_MARKET_INSTALLED_EMPTY_UPDATES_TITLE'
					: 'MOBILE_MARKET_INSTALLED_EMPTY_TITLE',
			),
			'description' => (string)Loc::getMessage(
				$isUpdatesFilter
					? 'MOBILE_MARKET_INSTALLED_EMPTY_UPDATES_DESCRIPTION'
					: 'MOBILE_MARKET_INSTALLED_EMPTY_DESCRIPTION',
			),
		];
	}
}
