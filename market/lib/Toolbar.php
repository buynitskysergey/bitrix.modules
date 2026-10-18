<?php

namespace Bitrix\Market;

use Bitrix\Market\Subscription\Status;
use Bitrix\Rest\Marketplace\Client;
use CRestUtil;

class Toolbar
{
	public static function getInfo($marketAction, $searchAction): array
	{
		$result = [
			'CATEGORIES' => Categories::forceGet(),
			'FAV_NUMBERS' => count(AppFavoritesTable::getUserFavorites()),
			'MENU_INFO' => Menu::getList(),
			'MARKET_SLIDER' => Status::getSlider(),
			'MARKET_ACTION' => $marketAction,
			'SEARCH_ACTION' => $searchAction,
		];

		if (CRestUtil::isAdmin()) {
			$result['NUM_UPDATES'] = Client::getAvailableUpdateNum();
		}

		return $result;
	}

	public static function getTotalAppsInfo(array $totalAppsResponse): array
	{
		return [
			'TOTAL_APPS' => NumberApps::get($totalAppsResponse),
			'SHOW_MARKET_ICON' => $totalAppsResponse['SHOW_MARKET_ICON'] ?? '',
			'ADDITIONAL_CONTENT' => $totalAppsResponse['ADDITIONAL_CONTENT'] ?? '',
			'ADDITIONAL_MARKET_ACTION' => $totalAppsResponse['ADDITIONAL_MARKET_ACTION'] ?? '',
			'ADDITIONAL_SEARCH_ACTION' => $totalAppsResponse['ADDITIONAL_SEARCH_ACTION'] ?? '',
			'ADDITIONAL_HIT_ACTION' => $totalAppsResponse['ADDITIONAL_HIT_ACTION'] ?? '',
			'SEARCH_FILTERS' => self::prepareSearchFilters($totalAppsResponse['MARKET_SEARCH_FILTERS'] ?? []),
			'MARKET_LOGO_TITLE' => $totalAppsResponse['MARKET_LOGO_TITLE'] ?? '',
			'MARKET_TOOLBAR_TITLE' => $totalAppsResponse['MARKET_TOOLBAR_TITLE'] ?? '',
			'MARKET_NAME_MESSAGE_CODE' => $totalAppsResponse['MARKET_NAME_MESSAGE_CODE'] ?? '',
		];
	}

	private static function prepareSearchFilters($searchFilters): array
	{
		if (!is_array($searchFilters) || !is_array($searchFilters['LIST'] ?? null)) {
			return [];
		}

		$namedItems = array_values(array_filter(
			$searchFilters['LIST'],
			static fn($item): bool => is_array($item) && is_string($item['NAME'] ?? null) && $item['NAME'] !== ''
		));

		if (empty($namedItems)) {
			return [];
		}

		$searchFilters['LIST'] = $namedItems;

		return $searchFilters;
	}
}