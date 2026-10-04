<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Market\Provider;

use Bitrix\Main\Localization\Loc;
use Bitrix\Mobile\Internal\Integration\Market\MarketService;
use Bitrix\Mobile\Market\Dto\HomeData;
use Bitrix\Mobile\Market\Dto\Promo;
use Bitrix\Mobile\Market\Mapper\CategoryMapper;

final class HomeDataProvider
{
	private readonly MarketService $marketService;

	public function __construct(?MarketService $marketService = null)
	{
		$this->marketService = $marketService ?? new MarketService();
	}

	public function getData(): HomeData
	{
		$title = (string)Loc::getMessage('MOBILE_MARKET_HOME_TITLE');
		$data = new HomeData(
			title: $title,
		);

		if (!$this->marketService->isMarketAvailable())
		{
			return $data;
		}

		return new HomeData(
			isAvailable: true,
			title: $title,
			categories: (new CategoryMapper($this->marketService))->mapCollection(
				$this->marketService->getHomeCategories(),
			),
			promo: $this->preparePromo(),
			canViewInstalledList: $this->marketService->canViewInstalledList(),
		);
	}

	private function preparePromo(): ?Promo
	{
		$freeDays = $this->marketService->getPromoFreeDays();

		if ($freeDays <= 0)
		{
			return null;
		}

		return new Promo(
			freeDays: $freeDays,
		);
	}
}
