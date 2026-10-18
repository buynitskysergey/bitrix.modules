<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service\Admission\Strategy\APAuth;

use Bitrix\Rest\Contract\Service\APAuth\PasswordService;
use Bitrix\Rest\Internal\Exception\VibePlus\FeatureNotAvailableOnCurrentPlanException;
use Bitrix\Rest\Internal\Service\Admission\DenialStrategyInterface;
use Bitrix\Rest\Internal\Service\Admission\Verdict;
use Bitrix\Rest\Internal\Service\VibePlus\TariffAccessService;

final class TariffStrategy implements DenialStrategyInterface
{
	public function __construct(
		private readonly int $passwordId,
		private readonly TariffAccessService $tariffAccessService,
		private readonly PasswordService $passwordService,
		private readonly ?\CRestServer $restServer,
	)
	{
	}

	public function resolve(): ?Verdict
	{
		$availability = $this->tariffAccessService->getRestAccessAvailability();
		if ($availability === null)
		{
			return null;
		}

		if ($availability)
		{
			return Verdict::grant();
		}

		$isAPAuthAvailable = $this->tariffAccessService->getAPAuthAvailability(
			$this->passwordService->isSystemPasswordById($this->passwordId),
			$this->restServer?->getMethod(),
			SystemPasswordAllowedMethods::METHODS,
			$availability,
		);

		return $isAPAuthAvailable === false
			? Verdict::deny(new FeatureNotAvailableOnCurrentPlanException())
			: Verdict::grant();
	}
}
