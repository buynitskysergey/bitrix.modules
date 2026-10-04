<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Market;

final class Navigation
{
	public const HOME_FROM = 'mobile_market_home';
	public const CATEGORY_LIST_FROM = 'mobile_market_list';
	public const INSTALLED_LIST_FROM = 'mobile_market_installed_list';
	public const SEARCH_LIST_FROM = 'mobile_market_search';

	private const NATIVE_CATEGORY_ROUTE_TEMPLATE = '/market/category/#categoryCode#/';

	public static function getNativeCategoryUrl(string $categoryCode): string
	{
		return str_replace(
			'#categoryCode#',
			rawurlencode($categoryCode),
			self::NATIVE_CATEGORY_ROUTE_TEMPLATE,
		);
	}
}
