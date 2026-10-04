<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Market\Provider;

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\Mobile\Internal\Integration\Market\MarketService;
use Bitrix\Mobile\Market\AppListType;
use Bitrix\Mobile\Market\Dto\AppList;
use Bitrix\Mobile\Market\Mapper\ListItemMapper;
use Bitrix\Mobile\Market\Navigation;

final class SearchDataProvider
{
	private readonly MarketService $marketService;

	public function __construct(?MarketService $marketService = null)
	{
		$this->marketService = $marketService ?? new MarketService();
	}

	public function getData(
		string $query,
		int $page = 1,
		?PageNavigation $pageNavigation = null,
	): AppList
	{
		$page = max(1, (int)($pageNavigation?->getCurrentPage() ?? $page));
		$query = trim($query);
		$isMarketAvailable = $this->marketService->isMarketAvailable();

		$data = new AppList(
			isAvailable: $isMarketAvailable,
			listType: AppListType::Search,
			emptyState: $this->prepareEmptyState(),
			unavailableState: $this->prepareUnavailableState(),
		);

		if ($query === '' || !$isMarketAvailable)
		{
			return $data;
		}

		$result = $this->marketService->getSearchListInfo($query, $page);

		return new AppList(
			isAvailable: true,
			listType: AppListType::Search,
			items: (new ListItemMapper($this->marketService))->mapCollection(
				$result['APPS'] ?? [],
				Navigation::SEARCH_LIST_FROM,
			),
			pagination: AppList::createPagination(
				currentPage: (int)($result['CUR_PAGE'] ?? 1),
				pages: (int)($result['PAGES'] ?? 1),
			),
			emptyState: $this->prepareEmptyState(),
			unavailableState: $this->prepareUnavailableState(),
		);
	}

	private function prepareUnavailableState(): array
	{
		return [
			'title' => (string)Loc::getMessage('MOBILE_MARKET_SEARCH_UNAVAILABLE_TITLE'),
			'description' => (string)Loc::getMessage('MOBILE_MARKET_SEARCH_UNAVAILABLE_DESCRIPTION'),
		];
	}

	private function prepareEmptyState(): array
	{
		return [
			'title' => (string)Loc::getMessage('MOBILE_MARKET_SEARCH_EMPTY_TITLE'),
			'description' => (string)Loc::getMessage('MOBILE_MARKET_SEARCH_EMPTY_DESCRIPTION'),
		];
	}
}
