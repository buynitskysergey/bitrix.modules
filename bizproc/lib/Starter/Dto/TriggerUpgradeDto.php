<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Starter\Dto;

final readonly class TriggerUpgradeDto
{
	/**
	 * @param string $triggerType Actual type the deprecated one is rebuilt as.
	 * @param array<string, mixed> $properties Properties the upgraded node gets on top of its own.
	 */
	public function __construct(
		public string $triggerType,
		public array $properties = [],
	)
	{}
}
