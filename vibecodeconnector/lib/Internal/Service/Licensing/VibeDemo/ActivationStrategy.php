<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Licensing\VibeDemo;

use Bitrix\Main\Type\DateTime;

interface ActivationStrategy
{
	public const DEFAULT_DURATION_DAYS = 15;
	public const MAX_DURATION_DAYS = 3650;

	public function isApplicable(): bool;

	public function isActivated(): bool;

	public function findExpireDate(): ?DateTime;

	public function activate(int $days): void;

	public function hasGrantedTrial(): bool;
}
