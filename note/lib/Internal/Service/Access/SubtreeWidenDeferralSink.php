<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Access;

/**
 * [P4.T6] Optional sink that a caller injects into {@see SubtreeAclReconciler} to move the heavy
 * WIDEN pass of a source off the request thread. When present, an over-threshold widen is enqueued
 * (idempotent, deduped by source) instead of being materialised inline; a durable background agent
 * then converges the source's subtree to its CURRENT target.
 *
 * Kept as an interface so the Internal layer never hard-depends on the agent infrastructure: the
 * default reconciler has no sink and stays fully synchronous (P3 semantics), and only callers that
 * opt in (the ACL save-path) pass the concrete scheduler.
 */
interface SubtreeWidenDeferralSink
{
	/**
	 * Register a source whose widen pass is deferred to the background. Implementations MUST be
	 * idempotent per source and safe under concurrent callers.
	 */
	public function enqueue(int $sourceId, int $collectionId): void;
}
