<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Market\Provider;

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\Mobile\Internal\Integration\Market\MarketService;
use Bitrix\Mobile\Market\AppListType;
use Bitrix\Mobile\Market\Dto\AppList;
use Bitrix\Mobile\Market\Mapper\ListItemMapper;
use Bitrix\Mobile\Market\Mapper\SortInfoMapper;
use Bitrix\Mobile\Market\Mapper\TabsMapper;
use Bitrix\Mobile\Market\Navigation;

final class CategoryListDataProvider
{
	private readonly MarketService $marketService;

	public function __construct(?MarketService $marketService = null)
	{
		$this->marketService = $marketService ?? new MarketService();
	}

	public function getData(
		string $categoryCode,
		int $page = 1,
		string $developerTag = '',
		array $order = [],
		?PageNavigation $pageNavigation = null,
	): AppList
	{
		$page = max(1, (int)($pageNavigation?->getCurrentPage() ?? $page));
		$developerTag = $this->normalizeDeveloperTag($developerTag);

		$data = new AppList(
			listType: AppListType::Category,
			categoryCode: $categoryCode,
			developerTag: $developerTag,
			title: (string)Loc::getMessage('MOBILE_MARKET_CATEGORY_LIST_TITLE'),
			unavailableState: $this->prepareUnavailableState(),
		);

		if ($categoryCode === '' || !$this->marketService->isMarketAvailable())
		{
			return $data;
		}

		$result = $this->marketService->getCategoryListInfo($categoryCode, $page, $developerTag, $order);
		$sortInfo = (new SortInfoMapper())->map($result['SORT_INFO'] ?? null);

		return new AppList(
			isAvailable: true,
			listType: AppListType::Category,
			categoryCode: $categoryCode,
			developerTag: $developerTag,
			title: (string)($result['TITLE'] ?? $data->title),
			sortInfo: $sortInfo,
			showSortMenu: (($result['SHOW_SORT_MENU'] ?? 'N') === 'Y' && $sortInfo !== null),
			tabs: (new TabsMapper())->mapCategoryTabs($result, $developerTag),
			items: (new ListItemMapper($this->marketService))->mapCollection(
				$result['APPS'] ?? [],
				Navigation::CATEGORY_LIST_FROM,
			),
			pagination: AppList::createPagination(
				currentPage: (int)($result['CUR_PAGE'] ?? 1),
				pages: (int)($result['PAGES'] ?? 1),
			),
			unavailableState: $this->prepareUnavailableState(),
		);
	}

	private function prepareUnavailableState(): array
	{
		return [
			'title' => (string)Loc::getMessage('MOBILE_MARKET_CATEGORY_LIST_UNAVAILABLE_TITLE'),
			'description' => (string)Loc::getMessage('MOBILE_MARKET_CATEGORY_LIST_UNAVAILABLE_DESCRIPTION'),
		];
	}

	private function normalizeDeveloperTag(string $developerTag): string
	{
		$developerTag = trim($developerTag);

		return $developerTag === '__all__' ? '' : $developerTag;
	}
}
