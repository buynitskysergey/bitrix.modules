<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Service\ActivityGroup;

use Bitrix\Bizproc\Activity\Enum\ActivityGroup;
use Bitrix\Main\Config\Option;

/**
 * Hides an activity group until a module option turns it on: the group stays hidden while the
 * option value differs from 'Y'. Suits groups still under internal rollout, so a release ships
 * them hidden and development enables them per portal.
 *
 * Reusable for any option gated group: register one instance per group in bizproc/.settings.php.
 */
class OptionGroupRule implements HideableGroupRuleInterface
{
	public function __construct(
		private readonly ActivityGroup $group,
		private readonly string $moduleId,
		private readonly string $optionName,
	)
	{
	}

	public function getGroup(): ActivityGroup
	{
		return $this->group;
	}

	public function isHidden(): bool
	{
		return $this->getOptionValue() !== 'Y';
	}

	// the single reading point of the option: overridable, so the rule stays testable without a portal
	protected function getOptionValue(): string
	{
		return Option::get($this->moduleId, $this->optionName, 'N');
	}
}
