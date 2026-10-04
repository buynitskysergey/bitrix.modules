<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Bitrix24;

use Bitrix\Bitrix24\Public\Service\VibePlus\UpsellProjectionProvider;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Throwable;

final class VibePlusCatalogUpsellProvider
{
	private readonly \Closure $projectionResolver;

	public function __construct(
		private readonly VibePlusPolicy $vibePlusPolicy = new VibePlusPolicy(),
		?\Closure $projectionResolver = null,
	)
	{
		$this->projectionResolver = $projectionResolver
			?? fn(int $userId): ?string => $this->resolvePromoterCode($userId);
	}

	public function getPromoterCode(int $userId): ?string
	{
		try
		{
			if ($userId <= 0 || $this->vibePlusPolicy->getAvailability() !== false)
			{
				return null;
			}

			$promoterCode = ($this->projectionResolver)($userId);

			return is_string($promoterCode) && $promoterCode !== '' ? $promoterCode : null;
		}
		catch (Throwable)
		{
			return null;
		}
	}

	private function resolvePromoterCode(int $userId): ?string
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return null;
		}

		$serviceLocator = ServiceLocator::getInstance();
		if (!$serviceLocator->has(UpsellProjectionProvider::class))
		{
			return null;
		}

		return $serviceLocator
			->get(UpsellProjectionProvider::class)
			->getProjectionForUser($userId)
			?->getPromoterCode()
		;
	}
}
