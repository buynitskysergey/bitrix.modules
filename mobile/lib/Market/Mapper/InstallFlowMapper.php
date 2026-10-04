<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Market\Mapper;

use Bitrix\Mobile\Internal\Integration\Market\MarketService;
use Bitrix\Mobile\Market\Dto\Agreement;
use Bitrix\Mobile\Market\Dto\App;
use Bitrix\Mobile\Market\Dto\InstallInfo;

final class InstallFlowMapper
{
	public function __construct(
		private readonly MarketService $marketService,
	)
	{
	}

	public function mapApp(array $appData): App
	{
		return new App(
			code: (string)($appData['CODE'] ?? ''),
			title: (string)($appData['NAME'] ?? ''),
		);
	}

	public function mapAgreements(array $appData): array
	{
		$licenseInfo = $this->marketService->getLicenseInfo($appData);
		$agreements = [];

		if (!empty($licenseInfo['TERMS_OF_SERVICE_LINK']))
		{
			$agreements[] = new Agreement(
				id: 'termsOfService',
				url: (string)$licenseInfo['TERMS_OF_SERVICE_LINK'],
			);
		}

		if (!empty($licenseInfo['EULA_LINK']))
		{
			$agreements[] = new Agreement(
				id: 'eula',
				url: (string)$licenseInfo['EULA_LINK'],
			);
		}

		if (!empty($licenseInfo['PRIVACY_LINK']))
		{
			$agreements[] = new Agreement(
				id: 'privacy',
				url: (string)$licenseInfo['PRIVACY_LINK'],
			);
		}

		return $agreements;
	}

	public function mapInstallInfo(array $installInfo): InstallInfo
	{
		return new InstallInfo(
			appCode: (string)($installInfo['CODE'] ?? ''),
			appVersion: (int)($installInfo['VERSION'] ?? 0),
			checkHash: (string)($installInfo['CHECK_HASH'] ?? ''),
			installHash: (string)($installInfo['INSTALL_HASH'] ?? ''),
		);
	}
}
