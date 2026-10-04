<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Market\Provider;

use Bitrix\Mobile\Internal\Integration\Market\MarketService;
use Bitrix\Mobile\Market\Dto\InstallFlowData;
use Bitrix\Mobile\Market\Mapper\InstallFlowMapper;

final class InstallFlowDataProvider
{
	private readonly MarketService $marketService;

	public function __construct(?MarketService $marketService = null)
	{
		$this->marketService = $marketService ?? new MarketService();
	}

	public function getData(
		string $code,
		int $version = 0,
		string $checkHash = '',
		string $installHash = '',
	): InstallFlowData
	{
		if (
			$code === ''
			|| !$this->marketService->isMarketAvailable()
			|| !$this->marketService->isRestAvailable()
		)
		{
			return new InstallFlowData();
		}

		$appData = $this->marketService->loadInstallAppData($code, $version, $checkHash, $installHash);
		if (empty($appData) || !$this->marketService->canOpenInstallFlow($appData))
		{
			return new InstallFlowData();
		}

		$installInfo = $this->marketService->getInstallInfo($appData, $checkHash, $installHash);
		$mapper = new InstallFlowMapper($this->marketService);
		$installInfoDto = $mapper->mapInstallInfo($installInfo);

		if ($installInfoDto->appCode === '')
		{
			return new InstallFlowData();
		}

		return new InstallFlowData(
			isAvailable: true,
			app: $mapper->mapApp($appData),
			scopes: $this->marketService->getApplicationRightsInfo((array)($appData['RIGHTS'] ?? [])),
			agreements: $mapper->mapAgreements($appData),
			installInfo: $installInfoDto,
		);
	}
}
