<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\Storage;

use Closure;

final class LicenseRemainingDiskSpaceProvider implements RemainingDiskSpaceProviderInterface
{
	/** @var Closure(): ?DiskSpaceLimit */
	private readonly Closure $limitResolver;

	/** @param Closure(): ?DiskSpaceLimit $limitResolver returns null when the portal quota is unknown */
	public function __construct(Closure $limitResolver)
	{
		$this->limitResolver = $limitResolver;
	}

	public function getRemainingBytes(): ?int
	{
		$limit = ($this->limitResolver)();
		if ($limit === null)
		{
			return null;
		}

		return self::calculateRemainingBytes($limit->targetBytes, $limit->usedBytes);
	}

	/**
	 * @return int|null free bytes left, null when the target value tells nothing about the tariff limit
	 */
	public static function calculateRemainingBytes(int $targetBytes, int $usedBytes): ?int
	{
		if ($targetBytes <= 0)
		{
			return null;
		}

		return max(0, $targetBytes - $usedBytes);
	}
}
