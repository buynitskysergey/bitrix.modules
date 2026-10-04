<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Agent\Access;

use Bitrix\Note\Internal\Service\Access\SubtreeWidenDeferralSink;

/**
 * [P4.T6] Hands an over-threshold widen pass to {@see SubtreeAclReconcileAgent}.
 *
 * Deferred work is not kept anywhere of its own: registering the agent IS the record that the source
 * is pending, because the agent's b_agent row carries the source, its collection and the resume
 * cursor in its name. See {@see SubtreeAclReconcileAgent} for what that buys.
 */
final class SubtreeAclReconcileScheduler implements SubtreeWidenDeferralSink
{
	public function enqueue(int $sourceId, int $collectionId): void
	{
		SubtreeAclReconcileAgent::schedule($sourceId, $collectionId);
	}
}
