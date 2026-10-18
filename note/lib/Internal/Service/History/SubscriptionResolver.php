<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\History;

use Bitrix\Note\Internal\Model\SubscriptionTable;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Repository\SubscriptionRepository;
use Bitrix\Note\Internal\Service\Subscription\CoverageResolver;

/**
 * Recipient sets for a document/collection event. Only collects user ids; it does NOT check
 * per-user visibility of the event (role matrix, ACL) — that gate lives at send time, same as
 * "losing access does not delete the subscription row".
 *
 * Delivery reads exactly one of these methods: NotificationRecipientService asks
 * resolveDirectDocumentSubscribers() for the article's own subscribers in the modes shipping
 * declares. The wider resolvers beside it — inherited subtree coverage, the knowledge base scope
 * and the mute that overrides both — have no caller outside their tests; the SDD keeps them as the
 * point to extend the day those modes and scopes are shipped, so the rules stay written down in
 * one place instead of being reconstructed then.
 */
final class SubscriptionResolver
{
	public function __construct(
		private readonly DocumentRepository $documentRepository = new DocumentRepository(),
		private readonly SubscriptionRepository $subscriptionRepository = new SubscriptionRepository(),
		private ?CoverageResolver $coverageResolver = null,
	) {}

	private function coverageResolver(): CoverageResolver
	{
		return $this->coverageResolver ??= new CoverageResolver($this->subscriptionRepository, $this->documentRepository);
	}

	/**
	 * Direct document subscribers (any MODE) + subtree subscribers on any ancestor
	 * + collection subscribers, deduplicated.
	 *
	 * @return int[]
	 */
	public function resolveDocumentRecipients(int $documentId, ?int $collectionId): array
	{
		$userIds = [];

		foreach ($this->subscriptionRepository->findSubscriberUserIds(SubscriptionTable::SCOPE_DOCUMENT, [$documentId]) as $userId)
		{
			$userIds[$userId] = true;
		}

		// subtree is a live rule: re-walked from the current tree on every resolve, never
		// materialized. The chain comes from CoverageResolver so that "which ancestors can cover
		// this document" is defined in one place for recipients and for the bell alike.
		$ancestorIds = $this->coverageResolver()->resolveAncestorIds([$documentId])[$documentId] ?? [];
		if (!empty($ancestorIds))
		{
			foreach (
				$this->subscriptionRepository->findSubscriberUserIds(
					SubscriptionTable::SCOPE_DOCUMENT,
					$ancestorIds,
					SubscriptionTable::MODE_SUBTREE,
				) as $userId
			)
			{
				$userIds[$userId] = true;
			}
		}

		if ($collectionId !== null && $collectionId > 0)
		{
			foreach ($this->subscriptionRepository->findSubscriberUserIds(SubscriptionTable::SCOPE_COLLECTION, [$collectionId]) as $userId)
			{
				$userIds[$userId] = true;
			}
		}

		// Per-document mute (MODE_MUTED) is a negative override: it removes the user from THIS
		// document's recipients even when an ancestor subtree / collection subscription covered them.
		// Applied last so it wins over every positive source above.
		foreach ($this->subscriptionRepository->findSubscriberUserIds(SubscriptionTable::SCOPE_DOCUMENT, [$documentId], SubscriptionTable::MODE_MUTED) as $userId)
		{
			unset($userIds[$userId]);
		}

		return array_keys($userIds);
	}

	/**
	 * Subscribers of the document's own row, narrowed to the given modes — the shipping source of
	 * recipients. Walks no ancestors and reads no collection row, so inherited coverage and the mute
	 * that overrides it play no part; a mode absent from $modes reads as "not subscribed".
	 *
	 * One query per mode: the repository narrows by a single MODE, and shipping declares one
	 * (MODES-01), so delivery pays a single query per group.
	 *
	 * @param string[] $modes
	 * @return int[]
	 */
	public function resolveDirectDocumentSubscribers(int $documentId, array $modes): array
	{
		$userIds = [];
		foreach ($modes as $mode)
		{
			foreach (
				$this->subscriptionRepository->findSubscriberUserIds(
					SubscriptionTable::SCOPE_DOCUMENT,
					[$documentId],
					$mode,
				) as $userId
			)
			{
				$userIds[$userId] = true;
			}
		}

		return array_keys($userIds);
	}

	/**
	 * @return int[]
	 */
	public function resolveCollectionRecipients(int $collectionId): array
	{
		if ($collectionId <= 0)
		{
			return [];
		}

		return $this->subscriptionRepository->findSubscriberUserIds(SubscriptionTable::SCOPE_COLLECTION, [$collectionId]);
	}
}
