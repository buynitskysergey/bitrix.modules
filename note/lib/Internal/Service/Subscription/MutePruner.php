<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Subscription;

use Bitrix\Note\Internal\Model\SubscriptionTable;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Repository\SubscriptionRepository;

/**
 * A mute (SCOPE=document, MODE=muted) is a negative override: it suppresses notifications the user
 * would otherwise get from *inherited* coverage (a subtree subscription on an ancestor, or a
 * subscription on the document's collection). Once that coverage is removed the mute suppresses
 * nothing — and worse, it silently re-suppresses the document if the user later re-subscribes to the
 * covering scope. So after any unsubscribe we drop the user's mutes that no longer cover anything.
 *
 * Iterates the user's own mute rows (a handful in practice), not the removed subtree's descendants —
 * so cost is bounded by how many documents this user has muted, not by tree size.
 */
final class MutePruner
{
	public function __construct(
		private readonly SubscriptionRepository $subscriptionRepository = new SubscriptionRepository(),
		private readonly DocumentRepository $documentRepository = new DocumentRepository(),
	) {}

	public function pruneOrphaned(int $userId): void
	{
		if ($userId <= 0)
		{
			return;
		}

		$mutedIds = $this->subscriptionRepository->findMutedDocumentIds($userId);
		if (empty($mutedIds))
		{
			return;
		}

		$collectionByDoc = $this->documentRepository->getCollectionIds($mutedIds);

		$orphaned = [];
		foreach ($mutedIds as $documentId)
		{
			if (!$this->hasCoverage($userId, $documentId, (int)($collectionByDoc[$documentId] ?? 0)))
			{
				$orphaned[] = $documentId;
			}
		}

		$this->subscriptionRepository->removeMutes($userId, $orphaned);
	}

	private function hasCoverage(int $userId, int $documentId, int $collectionId): bool
	{
		$ancestorIds = $this->documentRepository->getAncestorIds($documentId);
		if (
			!empty($ancestorIds)
			&& $this->subscriptionRepository->userHasSubscription($userId, SubscriptionTable::SCOPE_DOCUMENT, $ancestorIds, SubscriptionTable::MODE_SUBTREE)
		)
		{
			return true;
		}

		return $collectionId > 0
			&& $this->subscriptionRepository->userHasSubscription($userId, SubscriptionTable::SCOPE_COLLECTION, [$collectionId]);
	}
}
