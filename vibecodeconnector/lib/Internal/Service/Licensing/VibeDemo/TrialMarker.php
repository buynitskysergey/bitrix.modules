<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Licensing\VibeDemo;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Type\DateTime;
use Bitrix\Vibecodeconnector\Internal\Config\ModuleOptions;

final class TrialMarker
{
	private const ACTIVATED_AT_OPTION = 'vibe_demo_trial_activated_at';
	private const EXPIRES_AT_OPTION = 'vibe_demo_trial_expires_at';
	private const STRATEGY_OPTION = 'vibe_demo_trial_strategy';
	private const RESET_OPTION_NAME = 'vibe_demo_reset_enabled';

	public function __construct(private readonly ModuleOptions $options = new ModuleOptions())
	{
	}

	public function isMarked(): bool
	{
		return $this->getActivatedAt() !== null;
	}

	public function isResetAllowed(): bool
	{
		return $this->options->get(self::RESET_OPTION_NAME) === 'Y';
	}

	public function getActivatedAt(): ?string
	{
		return $this->read(self::ACTIVATED_AT_OPTION);
	}

	public function getExpireDate(): ?string
	{
		return $this->read(self::EXPIRES_AT_OPTION);
	}

	public function mark(?DateTime $expireDate, string $strategy, bool $isVibePlusTrial = false): void
	{
		$activatedAt = time();
		$this->options->set(self::ACTIVATED_AT_OPTION, date('c', $activatedAt));
		$this->options->set(self::EXPIRES_AT_OPTION, $expireDate === null ? '' : $expireDate->format('c'));
		$this->options->set(self::STRATEGY_OPTION, $strategy);
		if ($isVibePlusTrial && $expireDate !== null)
		{
			Option::set('bitrix24', 'vibe_plus_trial_start', (string)$activatedAt);
			Option::set('bitrix24', 'vibe_plus_trial_end', (string)$expireDate->getTimestamp());
		}
	}

	public function reset(): bool
	{
		if (!$this->isMarked())
		{
			return false;
		}

		foreach ([self::ACTIVATED_AT_OPTION, self::EXPIRES_AT_OPTION, self::STRATEGY_OPTION] as $name)
		{
			$this->options->delete($name);
		}

		return true;
	}

	private function read(string $name): ?string
	{
		$value = $this->options->get($name);

		return $value === '' ? null : $value;
	}
}
