<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Command\AiAgent\Enum;

/**
 * State a system AI agent lifecycle operation left the managed instance in.
 *
 * Backing values are the stable public representation of the outcome, so a calling module may store or
 * transfer them; internal identifiers of the instance never leave the module.
 */
enum SystemAiAgentLifecycleOutcome: string
{
	case Enabled = 'enabled';
	case AlreadyEnabled = 'already_enabled';
	case Disabled = 'disabled';
	case AlreadyDisabled = 'already_disabled';
	case CleanupPending = 'cleanup_pending';

	/**
	 * Tells whether an operation with this outcome succeeded.
	 *
	 * A case added later has to be listed here explicitly: an unlisted one raises \UnhandledMatchError
	 * instead of silently becoming a success.
	 *
	 * @return bool
	 */
	public function isSuccessful(): bool
	{
		return match ($this)
		{
			self::Enabled, self::AlreadyEnabled, self::Disabled, self::AlreadyDisabled => true,
			self::CleanupPending => false,
		};
	}
}
