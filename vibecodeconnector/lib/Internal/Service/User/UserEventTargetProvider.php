<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\User;

use Bitrix\Vibecodeconnector\Internal\Entity\Pairing\Pairing;
use Bitrix\Vibecodeconnector\Internal\Entity\User\UserEventTarget;
use Bitrix\Vibecodeconnector\Internal\Repository\Pairing\PairingRepository;
use Bitrix\Vibecodeconnector\Internal\Service\Auth\CloudSharedVerifier;
use Bitrix\Vibecodeconnector\Internal\Service\Endpoint\CloudEndpointProvider;

final class UserEventTargetProvider
{
	private const CLOUD_ISS = 'vibecode';

	public function __construct(
		private readonly PairingRepository $pairingRepository = new PairingRepository(),
		private readonly CloudSharedVerifier $cloudSharedVerifier = new CloudSharedVerifier(),
		private readonly CloudEndpointProvider $cloudEndpointProvider = new CloudEndpointProvider(),
	) {
	}

	public function resolve(string $iss): ?UserEventTarget
	{
		$pairing = $this->pairingRepository->findByIss($iss);
		if ($pairing !== null)
		{
			return $this->fromPairing($pairing);
		}

		if (!$this->cloudSharedVerifier->isApplicable($iss))
		{
			return null;
		}

		return new UserEventTarget($iss, $this->cloudEndpointProvider->getCloudUrl());
	}

	/**
	 * @return list<UserEventTarget>
	 */
	public function listAll(): array
	{
		$targetsByIss = [];
		foreach ($this->pairingRepository->listAll() as $pairing)
		{
			$key = $this->getTargetKey($pairing->iss);
			if (array_key_exists($key, $targetsByIss))
			{
				continue;
			}

			$targetsByIss[$key] = $this->fromPairing($pairing);
		}

		$cloudKey = $this->getTargetKey(self::CLOUD_ISS);
		if (
			!array_key_exists($cloudKey, $targetsByIss)
			&& $this->cloudSharedVerifier->isApplicable(self::CLOUD_ISS)
		)
		{
			$targetsByIss[$cloudKey] = new UserEventTarget(
				self::CLOUD_ISS,
				$this->cloudEndpointProvider->getCloudUrl(),
			);
		}

		return array_values($targetsByIss);
	}

	private function fromPairing(Pairing $pairing): UserEventTarget
	{
		return new UserEventTarget($pairing->iss, $pairing->endpointUrl);
	}

	private function getTargetKey(string $iss): string
	{
		return 'iss:' . $iss;
	}
}
