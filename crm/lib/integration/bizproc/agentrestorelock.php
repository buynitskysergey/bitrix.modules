<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\BizProc;

/**
 * Mutual exclusion of the restore of a system AI agent copy. Taking and releasing belong to one collaborator, so
 * nothing can hand over the one without the other.
 */
interface AgentRestoreLock
{
	public function acquire(): bool;

	/**
	 * Allowed only after an acquire() that returned true. Releasing a lock that was not taken leaves the connection
	 * pool one binding to the master short, and the next pair of bindings of somebody else stops working.
	 */
	public function release(): void;
}
