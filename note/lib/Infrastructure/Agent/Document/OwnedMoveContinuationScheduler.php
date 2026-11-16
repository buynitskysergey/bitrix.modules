<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Agent\Document;

use Bitrix\Note\Internal\Service\Bulk\OwnedMoveDeferralSink;

/**
 * Hands the tail of an author-scoped move to {@see OwnedMoveContinuationAgent}.
 *
 * Nothing is kept anywhere of its own: registering the agent IS the record that the move is
 * unfinished, because the b_agent row carries the caller, both bases, the target parent and the
 * resume cursor in its name.
 */
final class OwnedMoveContinuationScheduler implements OwnedMoveDeferralSink
{
	public function enqueue(int $userId, int $collectionId, int $targetCollectionId, ?int $targetParentId): void
	{
		OwnedMoveContinuationAgent::schedule($userId, $collectionId, $targetCollectionId, $targetParentId);
	}
}
