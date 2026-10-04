<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service\VibePlus;

use Bitrix\Bitrix24\Public\Service\VibePlus\UpsellProjectionProvider;
use Bitrix\Bitrix24\Public\ValueObject\VibePlusUpsellProjection;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Throwable;

final class IntegrationUpsellHelperCodeProvider
{
	private readonly \Closure $projectionResolver;

	public function __construct(?\Closure $projectionResolver = null)
	{
		$this->projectionResolver = $projectionResolver ?? static function(): ?VibePlusUpsellProjection {
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
				->getProjection()
			;
		};
	}

	public function getHelperCode(string $fallback): string
	{
		try
		{
			$projection = ($this->projectionResolver)();
		}
		catch (Throwable)
		{
			return $fallback;
		}

		return $projection instanceof VibePlusUpsellProjection
			? $projection->getPromoterCode()
			: $fallback;
	}
}
