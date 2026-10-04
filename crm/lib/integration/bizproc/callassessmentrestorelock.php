<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\BizProc;

use Bitrix\Main\Application;

/**
 * The named lock of the call assessment agent restore.
 *
 * The connection is bound to the master for as long as the lock is held. Connection::lock() binds it around the
 * lock statement alone, so on a portal with replicas a read under the lock could get a stale negative answer and
 * lead to a second active copy - the very thing the lock protects from.
 */
final class CallAssessmentRestoreLock implements AgentRestoreLock
{
	private const NAME = 'crm_call_assessment_agent_restore';

	// Non-blocking attempt: the restore also runs inside a hit, where waiting for another process is not an option.
	private const TIMEOUT = 0;

	public function acquire(): bool
	{
		$pool = Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);

		$acquired = false;
		try
		{
			$acquired = Application::getConnection()->lock(self::NAME, self::TIMEOUT);
		}
		finally
		{
			if (!$acquired)
			{
				// the binding lives as long as the lock does, and there is nothing to release it later
				$pool->useMasterOnly(false);
			}
		}

		return $acquired;
	}

	public function release(): void
	{
		try
		{
			Application::getConnection()->unlock(self::NAME);
		}
		finally
		{
			Application::getInstance()->getConnectionPool()->useMasterOnly(false);
		}
	}
}
