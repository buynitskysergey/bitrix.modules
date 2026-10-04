<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Controller;

use Bitrix\Main\Engine\ActionFilter\CloseSession;
use Bitrix\Main\Engine\JsonController;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\Mobile\Internal\Integration\Market\MarketService;
use Bitrix\Mobile\Market\Dto\AppList;
use Bitrix\Mobile\Market\Dto\HomeData;
use Bitrix\Mobile\Market\Dto\InstallFlowData;
use Bitrix\Mobile\Market\Provider\CategoryListDataProvider;
use Bitrix\Mobile\Market\Provider\HomeDataProvider;
use Bitrix\Mobile\Market\Provider\InstalledListDataProvider;
use Bitrix\Mobile\Market\Provider\InstallFlowDataProvider;
use Bitrix\Mobile\Market\Provider\SearchDataProvider;

final class Market extends JsonController
{
	protected function getDefaultPreFilters(): array
	{
		return array_merge(
			[
				new CloseSession(),
			],
			parent::getDefaultPreFilters(),
		);
	}

	/**
	 * @restMethod mobile.Market.getHomeData
	 */
	public function getHomeDataAction(): HomeData
	{
		return (new HomeDataProvider())->getData();
	}

	/**
	 * @restMethod mobile.Market.activateDemoSubscription
	 */
	public function activateDemoSubscriptionAction(): ?array
	{
		$result = (new MarketService())->activateDemoSubscription();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getData();
	}

	/**
	 * @restMethod mobile.Market.getCategoryListData
	 */
	public function getCategoryListDataAction(
		string $categoryCode,
		int $page = 1,
		string $developerTag = '',
		array $order = [],
		?PageNavigation $pageNavigation = null,
	): AppList
	{
		return (new CategoryListDataProvider())->getData(
			$categoryCode,
			$page,
			$developerTag,
			$order,
			$pageNavigation,
		);
	}

	/**
	 * @restMethod mobile.Market.getInstalledListData
	 */
	public function getInstalledListDataAction(
		int $page = 1,
		string $installedFilter = '',
		?PageNavigation $pageNavigation = null,
	): AppList
	{
		return (new InstalledListDataProvider())->getData(
			$page,
			$installedFilter,
			$pageNavigation,
		);
	}

	/**
	 * @restMethod mobile.Market.getSearchData
	 */
	public function getSearchDataAction(
		string $query,
		int $page = 1,
		?PageNavigation $pageNavigation = null,
	): AppList
	{
		return (new SearchDataProvider())->getData(
			$query,
			$page,
			$pageNavigation,
		);
	}

	/**
	 * @restMethod mobile.Market.getInstallFlowData
	 */
	public function getInstallFlowDataAction(
		string $code,
		int $version = 0,
		string $checkHash = '',
		string $installHash = '',
	): InstallFlowData
	{
		return (new InstallFlowDataProvider())->getData(
			$code,
			$version,
			$checkHash,
			$installHash,
		);
	}
}
