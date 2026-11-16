<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Bulk;

/**
 * Optional sink a caller injects into the author-scoped move to take the tail of a large set off
 * the request thread. When present, everything above the synchronous threshold is handed over and a
 * durable background agent finishes the move; without it the command stays fully synchronous.
 *
 * An interface, so the Internal layer never hard-depends on the agent infrastructure — the same
 * arrangement the ACL reconciler uses with {@see \Bitrix\Note\Internal\Service\Access\SubtreeWidenDeferralSink}.
 */
interface OwnedMoveDeferralSink
{
	/**
	 * Register the remainder of one author-scoped move. Implementations MUST be idempotent for the
	 * same operation and safe under concurrent callers. The set itself is not carried: the background
	 * worker resolves it again, because it changes as the move proceeds.
	 */
	public function enqueue(int $userId, int $collectionId, int $targetCollectionId, ?int $targetParentId): void;
}
