<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Internal\Integration\Market;

use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Uri;
use Bitrix\Market\Application;
use Bitrix\Market\Application\InstalledListAccess;
use Bitrix\Market\Categories;
use Bitrix\Market\Link;
use Bitrix\Market\ListTemplates;
use Bitrix\Market\Menu;
use Bitrix\Market\PricePolicy;
use Bitrix\Rest\AppTable;
use Bitrix\Rest\Infrastructure\Market\MarketSubscription;
use Bitrix\Rest\Internal\Integration\Market\Subscription as RestMarketSubscription;
use Bitrix\Rest\Marketplace\Client;

final class MarketService
{
	private const PROMO_DEFAULT_FREE_DAYS = 45;

	public function isMarketAvailable(): bool
	{
		return Loader::includeModule('market');
	}

	public function isRestAvailable(): bool
	{
		return Loader::includeModule('rest');
	}

	public function getHomeCategories(): array
	{
		$categories = Categories::forceGet(true);

		return is_array($categories['ITEMS'] ?? null) ? $categories['ITEMS'] : [];
	}

	public function getMarketDirUrl(string $from): string
	{
		return Link::getDir($from);
	}

	public function getMarketCategoryUrl(string $from): string
	{
		return Link::getMarketCategory($from);
	}

	public function getCategoryPageUrl(string $categoryCode, string $from): string
	{
		return Link::getCategoryPage($categoryCode, $from);
	}

	public function getDetailPageUrl(string $appCode, string $from): string
	{
		return Link::getDetailPage($appCode, $from);
	}

	public function getPromoFreeDays(): int
	{
		if (!$this->isRestAvailable())
		{
			return 0;
		}

		$subscription = MarketSubscription::createByDefault();
		if (!$subscription->isActive() && $subscription->isDemoAvailable())
		{
			// The web market module does not publish the inactive trial quota yet.
			return self::PROMO_DEFAULT_FREE_DAYS;
		}

		return 0;
	}

	public function activateDemoSubscription(): Result
	{
		$result = new Result();

		if (!$this->isRestAvailable())
		{
			return $result->addError(new Error('Rest module is not available'));
		}

		return (new RestMarketSubscription())->activateDemo();
	}

	public function getCategoryListInfo(
		string $categoryCode,
		int $page,
		string $developerTag = '',
		array $order = [],
	): array
	{
		$template = new ListTemplates\Category();
		$template->setMobileMarketContext();
		$template->setCategoryCode($categoryCode);
		$template->setPage($page);
		if ($developerTag !== '')
		{
			$template->setFilter([
				'categoryTag' => $developerTag,
			]);
		}
		if (!empty($order))
		{
			$template->setOrder($order);
		}
		$template->setResult();

		return $template->getInfo();
	}

	public function canViewInstalledList(): bool
	{
		return $this->isMarketAvailable() && InstalledListAccess::canView();
	}

	public function getInstalledListInfo(int $page, string $installedFilter = ''): array
	{
		$template = new ListTemplates\Installed();
		$template->setPage($page);
		if ($installedFilter !== '')
		{
			$template->setFilter([
				'tag' => $installedFilter,
			]);
		}
		$template->setResult();

		return $template->getInfo();
	}

	public function getSearchListInfo(string $query, int $page): array
	{
		$template = new ListTemplates\Search();
		$template->setMobileMarketContext();
		$template->setSearchText($query);
		$template->setPage($page);
		$template->setResult(true);

		return $template->getInfo();
	}

	public function getListItemButtons(array $app): array
	{
		return Application\Action::getButtons($app);
	}

	public function hasInstallButton(array $buttons): bool
	{
		return (($buttons[Application\Action::INSTALL] ?? '') === 'Y');
	}

	public function isApplicationActive(array $appData): bool
	{
		return (($appData['ACTIVE'] ?? 'N') === AppTable::ACTIVE);
	}

	public function isConfigurationApplication(array $appData): bool
	{
		return (($appData['TYPE'] ?? '') === AppTable::TYPE_CONFIGURATION);
	}

	public function getOpenAppUrl(string $appCode): string
	{
		if ($appCode === '' || !$this->isRestAvailable())
		{
			return '';
		}

		$appItem = Application\Installed::getByCode($appCode);
		if (!is_array($appItem) || (($appItem['ACTIVE'] ?? '') !== AppTable::ACTIVE))
		{
			return '';
		}

		$installedApps = Menu::getInstalledApps((int)($appItem['ID'] ?? 0));
		if (count($installedApps) !== 1)
		{
			return '';
		}

		$uri = new Uri((string)($installedApps[0]['PATH'] ?? ''));
		$uri->addParams(['from' => 'market_detail']);

		return $uri->getUri();
	}

	public function isSubscriptionInstallUnavailable(array $app): bool
	{
		return PricePolicy::getByApp($app) === PricePolicy::SUBSCRIPTION
			&& !$this->isSubscriptionAvailable();
	}

	public function loadInstallAppData(
		string $code,
		int $version = 0,
		string $checkHash = '',
		string $installHash = '',
	): array
	{
		$appExternal = Client::getApp(
			$code,
			$version,
			$checkHash ?: false,
			$installHash ?: false,
		);

		$appData = (array)($appExternal['ITEMS'] ?? []);
		if (empty($appData))
		{
			return [];
		}

		$appData['SILENT_INSTALL'] = (($appData['SILENT_INSTALL'] ?? 'N') === 'Y' ? 'Y' : 'N');
		$appData['VERSION'] = (int)($appData['VER'] ?? $appData['VERSION'] ?? $version);
		$appData['VER_TO_INSTALL'] = (int)($appData['VER'] ?? $appData['VERSION'] ?? $version);

		$appItem = Application\Installed::getByCode($code);
		if (!empty($appItem))
		{
			$appData['ID'] = (int)($appItem['ID'] ?? 0);
			$appData['INSTALLED'] = $appItem['INSTALLED'] ?? null;
			$appData['ACTIVE'] = $appItem['ACTIVE'] ?? 'N';
			$appData['STATUS'] = $appItem['STATUS'] ?? null;
			$appData['DATE_FINISH'] = $appItem['DATE_FINISH'] ?? null;
			$appData['IS_TRIALED'] = $appItem['IS_TRIALED'] ?? null;
			$appData['WAS_INSTALLED'] = 'Y';
			$appData['HAS_APP_FORM'] = !empty($appItem['URL_SETTINGS'])
				&& (($appData['OPEN_API'] ?? 'N') === 'Y');
		}

		return $appData;
	}

	public function canOpenInstallFlow(array $appData): bool
	{
		if ($this->isInstallFlowUnavailableBySubscription($appData))
		{
			return false;
		}

		$buttons = Application\Action::getButtons($appData);

		return
			(($buttons[Application\Action::INSTALL] ?? '') === 'Y')
			|| (($buttons[Application\Action::UPDATE] ?? '') === 'Y');
	}

	public function getApplicationRightsInfo(array $rights): array
	{
		return (new Application\Rights($rights))->getInfo();
	}

	public function getLicenseInfo(array $appData): array
	{
		return Application\License::getInfo($appData);
	}

	public function getInstallInfo(array $appData, string $checkHash, string $installHash): array
	{
		return Application\Action::getInstallJsInfo(
			$appData,
			($checkHash !== '' ? $checkHash : false),
			($installHash !== '' ? $installHash : false),
		);
	}

	private function isSubscriptionAvailable(): bool
	{
		return $this->isRestAvailable() && Client::isSubscriptionAvailable();
	}

	private function isInstallFlowUnavailableBySubscription(array $appData): bool
	{
		$isSubscriptionOnlyApp = (($appData['BY_SUBSCRIPTION'] ?? 'N') === 'Y')
			|| PricePolicy::getByApp($appData) === PricePolicy::SUBSCRIPTION;

		return $isSubscriptionOnlyApp && !$this->isSubscriptionAvailable();
	}
}
