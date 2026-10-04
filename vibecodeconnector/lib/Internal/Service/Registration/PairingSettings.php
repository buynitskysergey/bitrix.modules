<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Registration;

use Bitrix\Vibecodeconnector\Internal\Config\ModuleOptions;

final class PairingSettings
{
	protected const DEFAULT_MAX_TTL_SECONDS = 604800;
	public const MIN_TTL_SECONDS = 300;
	public const MAX_TTL_SECONDS = 31536000;

	private const OPTION_NAME = 'pairing_max_ttl_seconds';

	public function __construct(private readonly ModuleOptions $options = new ModuleOptions())
	{
	}

	public function getMaxTtlSeconds(): int
	{
		$value = (int)$this->options->get(self::OPTION_NAME, (string)self::DEFAULT_MAX_TTL_SECONDS);

		return $this->clamp($value);
	}

	public function setMaxTtlSeconds(int $seconds): void
	{
		$this->options->set(self::OPTION_NAME, (string)$this->clamp($seconds));
	}

	public function computeExpiresAt(int $fetchedAt, int $publicKeyTtl): int
	{
		$localMax = $this->getMaxTtlSeconds();
		$effective = $publicKeyTtl > 0 && $publicKeyTtl < $localMax ? $publicKeyTtl : $localMax;

		return $fetchedAt + $effective;
	}

	private function clamp(int $seconds): int
	{
		if ($seconds < self::MIN_TTL_SECONDS)
		{
			return self::MIN_TTL_SECONDS;
		}

		if ($seconds > self::MAX_TTL_SECONDS)
		{
			return self::MAX_TTL_SECONDS;
		}

		return $seconds;
	}
}
