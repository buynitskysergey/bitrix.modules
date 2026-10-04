<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Public\Service;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Type\DateTime;
use Bitrix\Vibecodeconnector\Internal\Integration\Bitrix24\VibePlusPolicy;
use Bitrix\Vibecodeconnector\Internal\Integration\Intranet\IntranetGate;
use Bitrix\Vibecodeconnector\Internal\Repository\Pairing\PairingRepository;
use Bitrix\Vibecodeconnector\Internal\Service\Auth\CloudSharedVerifier;

class AvailabilityService
{
	/** @var array<int, bool> */
	private static array $allowedMemo = [];

	public function __construct(
		private readonly PairingRepository $pairingRepository = new PairingRepository(),
		private readonly CloudSharedVerifier $cloudSharedVerifier = new CloudSharedVerifier(),
		private readonly IntranetGate $intranetGate = new IntranetGate(),
		private readonly ?object $options = null,
		private readonly ?object $vibeButtonAvailability = null,
		private readonly VibePlusPolicy $vibePlusPolicy = new VibePlusPolicy(),
	) {
	}

	public function isAvailableForUser(int $userId): bool
	{
		return $userId > 0 && $this->isEnabled() && $this->isAllowedForUser($userId);
	}

	/**
	 * Whether the catalog is allowed to the user type at all, regardless of the portal toggle.
	 */
	public function isAllowedForUser(int $userId): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		if (!array_key_exists($userId, self::$allowedMemo))
		{
			self::$allowedMemo[$userId] = $this->intranetGate->isIntranet($userId);
		}

		return self::$allowedMemo[$userId];
	}

	/**
	 * A cloud portal is ready as soon as the shared slot serves it: a missing key is obtained
	 * on the first incoming request, so readiness must not wait for the key to be stored.
	 */
	public function isReady(): bool
	{
		return $this->isCloudSlotAvailable() || $this->pairingRepository->hasAny();
	}

	public function isEnabled(): bool
	{
		return
			$this->getOption('is_ready', 'N') === 'Y'
			&& $this->vibePlusPolicy->isCatalogVisible()
		;
	}

	public function setEnabled(bool $value): void
	{
		$this->setOption('is_ready', $value ? 'Y' : 'N');
		$this->setOption('is_ready_set_at', (string)(new DateTime())->getTimestamp());
		if ($this->vibeButtonAvailability !== null)
		{
			$this->vibeButtonAvailability->set($value);
		}
		else
		{
			Option::set('immobile', 'is_vibecode_button_available', $value ? 'Y' : 'N');
		}
		self::$allowedMemo = [];
	}

	private function getOption(string $name, string $default): string
	{
		if ($this->options !== null)
		{
			return $this->options->get($name, $default);
		}

		return Option::get('vibecodeconnector', $name, $default);
	}

	private function setOption(string $name, string $value): void
	{
		if ($this->options !== null)
		{
			$this->options->set($name, $value);

			return;
		}

		Option::set('vibecodeconnector', $name, $value);
	}

	private function isCloudSlotAvailable(): bool
	{
		if (method_exists($this->cloudSharedVerifier, 'isSlotAvailable'))
		{
			return $this->cloudSharedVerifier->isSlotAvailable();
		}

		return $this->cloudSharedVerifier->isConfigured();
	}
}
