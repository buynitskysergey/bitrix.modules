<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Config;

use Bitrix\Main\Config\Option;

/**
 * The single source of the rollout flag of the workflow_state group: the group rule wired in
 * bizproc/.settings.php and the descriptions of the nodes of that group read the option through
 * this class only, so the group and its nodes never diverge in visibility.
 */
final class WorkflowStateGroupFeature
{
	public const MODULE_ID = 'bizproc';
	public const OPTION_NAME = 'workflow_state_group_available';

	// the generic OptionGroupRule reads any gated option with the same 'N' fallback: keep them in step
	private const DEFAULT_VALUE = 'N';

	public static function isAvailable(): bool
	{
		return Option::get(self::MODULE_ID, self::OPTION_NAME, self::DEFAULT_VALUE) === 'Y';
	}
}
