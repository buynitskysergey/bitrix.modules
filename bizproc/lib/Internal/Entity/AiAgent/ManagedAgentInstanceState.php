<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Entity\AiAgent;

/**
 * Lifecycle state of a managed system AI agent instance.
 *
 * Backing values are the tokens persisted in b_bp_managed_agent_instance.STATE.
 */
enum ManagedAgentInstanceState: string
{
	case Enabling = 'enabling';
	case Enabled = 'enabled';
	case Deleting = 'deleting';
	case CleanupPending = 'cleanup_pending';
}
