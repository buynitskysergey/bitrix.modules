<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Bitrix24\VibeDemo;

final class TrialTariffSet
{
	private function __construct(
		private readonly string $defaultTariff,
		private readonly array $tariffs,
	)
	{
	}

	public static function forPortal(Portal $portal): self
	{
		return $portal->isVibePlusTariffLineAvailable() ? self::vibePlusLine() : self::legacyLine();
	}

	public static function vibePlusLine(): self
	{
		return new self('pro100_vibe', ['basic_vibe', 'std_vibe', 'pro100_vibe']);
	}

	public static function legacyLine(): self
	{
		return new self('pro100', ['basic', 'std', 'pro100']);
	}

	public function getDefaultTariff(): string
	{
		return $this->defaultTariff;
	}

	public function findActiveTrialTariff(): ?string
	{
		foreach ($this->tariffs as $tariff)
		{
			if (TrialExpireDate::forEdition($tariff) !== null)
			{
				return $tariff;
			}
		}

		return null;
	}
}
