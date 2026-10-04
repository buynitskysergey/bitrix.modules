<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Market\Mapper;

use Bitrix\Main\Localization\Loc;
use Bitrix\Mobile\Internal\Integration\Market\MarketService;
use Bitrix\Mobile\Market\Dto\ListItem;
use Bitrix\Mobile\Market\Navigation;

final class ListItemMapper
{
	public function __construct(
		private readonly MarketService $marketService,
	)
	{
	}

	public function mapCollection(array $apps, string $detailNavigationFrom): array
	{
		$result = [];
		$isInstalledList = ($detailNavigationFrom === Navigation::INSTALLED_LIST_FROM);

		foreach ($apps as $app)
		{
			if (!is_array($app))
			{
				continue;
			}

			$listItem = $this->map($app, $detailNavigationFrom, $isInstalledList);
			if ($listItem !== null)
			{
				$result[] = $listItem;
			}
		}

		return $result;
	}

	public function map(array $app, string $detailNavigationFrom, bool $isInstalledList = false): ?ListItem
	{
		$code = (string)($app['CODE'] ?? '');
		if ($code === '')
		{
			return null;
		}

		$description = trim(strip_tags((string)($app['SHORT_DESC'] ?? $app['DESC'] ?? '')));
		$buttons = (is_array($app['BUTTONS'] ?? null) ? $app['BUTTONS'] : $this->marketService->getListItemButtons($app));
		$openAppUrl = ($isInstalledList ? $this->marketService->getOpenAppUrl($code) : '');
		$action = $this->mapAction(
			$app,
			$buttons,
			$isInstalledList,
			$openAppUrl,
		);

		return new ListItem(
			id: $code,
			code: $code,
			title: (string)($app['NAME'] ?? ''),
			description: $description,
			imageUrl: (string)($app['ICON'] ?? ''),
			installsCount: (int)($app['NUM_INSTALLS'] ?? 0),
			detailUrl: $this->marketService->getDetailPageUrl($code, $detailNavigationFrom),
			openAppUrl: $openAppUrl,
			actionTitle: $action['title'],
			actionType: $action['type'],
			isInstallAction: ($action['type'] === 'install'),
			showActionButton: $action['showButton'],
		);
	}

	private function mapAction(
		array $app,
		array $buttons,
		bool $isInstalledList = false,
		string $openAppUrl = '',
	): array
	{
		if ($isInstalledList)
		{
			return [
				'title' => ($openAppUrl !== '' ? (string)Loc::getMessage('MOBILE_MARKET_LIST_ACTION_OPEN') : ''),
				'type' => ($openAppUrl !== '' ? 'open' : 'none'),
				'showButton' => ($openAppUrl !== ''),
			];
		}

		$isInstallAction = $this->marketService->hasInstallButton($buttons);
		$isSubscriptionAction = (
			$isInstallAction
			&& $this->marketService->isSubscriptionInstallUnavailable($app)
		);

		if ($isSubscriptionAction)
		{
			return [
				'title' => (string)Loc::getMessage('MOBILE_MARKET_LIST_ACTION_SUBSCRIPTION'),
				'type' => 'subscription',
				'showButton' => true,
			];
		}

		if ($isInstallAction)
		{
			return [
				'title' => (string)Loc::getMessage('MOBILE_MARKET_LIST_ACTION_INSTALL'),
				'type' => 'install',
				'showButton' => true,
			];
		}

		return [
			'title' => (string)Loc::getMessage('MOBILE_MARKET_LIST_ACTION_DETAILS'),
			'type' => 'details',
			'showButton' => true,
		];
	}

}
