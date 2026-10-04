<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\AiAgentGrid\Upgrade;

/**
 * Result of merging reference (new) constants with the current copy constants.
 *
 * @see ConstantMergeService
 */
final class ConstantMergeResult
{
	/**
	 * @param array $target The constant set to persist on the copy: reference
	 *   constants with compatible values carried over from the copy by code.
	 * @param list<string> $newRequiredMissing Codes of constants that are new in
	 *   the reference, required, and have no value yet — must be filled in before
	 *   the upgrade can complete.
	 */
	public function __construct(
		public readonly array $target,
		public readonly array $newRequiredMissing,
	) {}

	public function hasNewRequiredMissing(): bool
	{
		return $this->newRequiredMissing !== [];
	}
}
