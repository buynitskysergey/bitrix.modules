<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Service;

use Bitrix\Rest\Internal\Service\VibePlus\TariffAccessService;
use Bitrix\Rest\Public\ValueObject\VibePlusMarketApplicationLimit;

final class VibePlusMarketApplicationLimitProvider
{
	private bool $projectionResolved = false;
	private VibePlusMarketApplicationLimit $projection;
	private bool $configuredLimitResolved = false;
	private ?int $configuredLimit = null;

	public function __construct(
		private readonly TariffAccessService $tariffAccessService = new TariffAccessService(),
		private readonly VibePlusUsageProvider $usageProvider = new VibePlusUsageProvider(),
	)
	{
	}

	public function getProjection(): VibePlusMarketApplicationLimit
	{
		if (!$this->projectionResolved)
		{
			$this->projection = $this->resolveProjection();
			$this->projectionResolved = true;
		}

		return $this->projection;
	}

	public function getConfiguredLimit(): ?int
	{
		if (!$this->configuredLimitResolved)
		{
			$this->configuredLimit = $this->resolveConfiguredLimit();
			$this->configuredLimitResolved = true;
		}

		return $this->configuredLimit;
	}

	private function resolveProjection(): VibePlusMarketApplicationLimit
	{
		try
		{
			$limitResult = $this->tariffAccessService->getMarketApplicationLimitResult();
			if (!$limitResult->isSuccess())
			{
				return VibePlusMarketApplicationLimit::unknown();
			}

			if (!$limitResult->isApplicable())
			{
				return VibePlusMarketApplicationLimit::notApplicable();
			}

			$count = $this->usageProvider->getMarketApplicationCount();
			$limit = $limitResult->getLimit();

			if ($limit === -1)
			{
				return VibePlusMarketApplicationLimit::unlimited($count, $this->getConfiguredLimit());
			}

			if ($limit === null || $limit < 0)
			{
				return VibePlusMarketApplicationLimit::unknown($count);
			}

			return VibePlusMarketApplicationLimit::finite($count, $limit);
		}
		catch (\Throwable)
		{
			return VibePlusMarketApplicationLimit::unknown();
		}
	}

	private function resolveConfiguredLimit(): ?int
	{
		try
		{
			$result = $this->tariffAccessService->getConfiguredMarketApplicationLimitResult();
			if (!$result->isSuccess() || !$result->isApplicable())
			{
				return null;
			}

			$limit = $result->getLimit();

			return $limit !== null && $limit >= -1 ? $limit : null;
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	/**
	 * @return string[]
	 */
	public function getCountedApplicationCodes(): array
	{
		return $this->usageProvider->getMarketApplicationCodes();
	}
}
