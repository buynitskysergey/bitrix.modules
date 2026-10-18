<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Notification;

use Bitrix\Note\Internal\Access\PortalAdmin;
use Bitrix\Note\Internal\Access\Service\CollectionAccessService;
use Bitrix\Note\Internal\Access\Service\DocumentAccessService;
use Bitrix\Note\Internal\Model\EventTable;
use Bitrix\Note\Internal\Model\SubscriptionTable;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Service\History\EventVisibilityPolicy;
use Bitrix\Note\Internal\Service\History\SubscriptionResolver;

/**
 * Turns "who is subscribed" into "who may actually be notified of
 * this compacted event group right now" — the send-time gate the drainer calls once
 * per group. Only document-scope groups are resolved: SCOPE=collection events are
 * not written in v1 (see SDD P7), so there is nothing else to look up here yet.
 *
 * Subscribers come from the article's own rows in the shipped modes (MODES-01). Inherited
 * coverage — a subtree row on an ancestor, a subscription to the whole knowledge base — is read
 * by the bell but delivers nothing, so the mute that overrides it has nothing left to suppress.
 *
 * Exclusion is sole authorship of the group: a recipient is kept out only when the authors of
 * everything the group folded are exactly themselves. A group carrying an event of somebody else
 * — A renamed the article, B rewrote it — reaches both of them.
 */
final class NotificationRecipientService
{
	public function __construct(
		private readonly SubscriptionResolver $subscriptionResolver = new SubscriptionResolver(),
		private readonly DocumentRepository $documentRepository = new DocumentRepository(),
		private readonly EventAuthorResolver $eventAuthorResolver = new EventAuthorResolver(),
	) {}

	/** @var array<int, string[]> userId -> access codes (stable within one drainer tick) */
	private array $accessCodesCache = [];
	/** @var array<string, int> "userId:documentId" -> effective document level */
	private array $documentLevelCache = [];
	/** @var array<string, int> "userId:collectionId" -> collection level */
	private array $collectionLevelCache = [];

	/**
	 * @return int[] recipient user ids — no anchors/pairs; the drainer derives the
	 *               aggregate anchor (collectionId) separately (ALG-03).
	 */
	public function resolve(CompactedEventGroup $group): array
	{
		if ($group->getScope() !== EventTable::SCOPE_DOCUMENT)
		{
			return [];
		}

		$documentId = $group->getEntityId();

		// Subscribers first: an article nobody follows is the common case, and every query below
		// — its meta row, its authors, its ACL — would be paid for a recipient set that is empty.
		$subscribers = $this->subscriptionResolver->resolveDirectDocumentSubscribers(
			$documentId,
			NotificationShippingPolicy::shippedModes(SubscriptionTable::SCOPE_DOCUMENT),
		);
		if (empty($subscribers))
		{
			return [];
		}

		$document = $this->documentRepository->getMetaById($documentId, ['ID', 'COLLECTION_ID']);
		if ($document === null)
		{
			return [];
		}

		$collectionId = (int)$document->getCollectionId();

		// Who made the change is the group's author set, not any event's USER_ID: a compaction is
		// fired by whichever client happened to flush the journal, who may have edited nothing.
		$groupAuthorIds = $this->eventAuthorResolver->resolveGroup($group);
		$soleAuthorId = count($groupAuthorIds) === 1 ? $groupAuthorIds[0] : null;
		$candidates = array_filter(
			$subscribers,
			static fn(int $userId): bool => $userId !== $soleAuthorId,
		);

		if (empty($candidates))
		{
			return [];
		}

		$eventType = $group->getRepresentative()->getEventType();
		$result = [];
		foreach ($candidates as $userId)
		{
			$snapshot = $this->buildAccessSnapshot($userId, $documentId, $collectionId);
			if (!$snapshot['canView'])
			{
				// Lost access since subscribing — the subscription row survives, the
				// notification does not (recheck at send time, not at subscribe time).
				continue;
			}

			if (!in_array($eventType, EventVisibilityPolicy::allowedTypes($snapshot), true))
			{
				continue;
			}

			$result[] = $userId;
		}

		return $result;
	}

	/**
	 * Rebuilds the {canEdit, canEditCollection, canView} snapshot that
	 * EventVisibilityPolicy/allowedTypes() expects, for an arbitrary recipient
	 * instead of CurrentUser — DocumentAccessService::getCurrentUserSnapshot() is
	 * hardwired to CurrentUser::get(), so it cannot be reused for a notification
	 * recipient that is not the request's current user.
	 *
	 * @return array{canEdit: bool, canEditCollection: bool, canView: bool}
	 */
	private function buildAccessSnapshot(int $userId, int $documentId, int $collectionId): array
	{
		if (PortalAdmin::isAdmin($userId))
		{
			return ['canEdit' => true, 'canEditCollection' => true, 'canView' => true];
		}

		// A subscriber recurs across the dedup groups of every article they follow in the same
		// batch, and access is immutable within a single tick — memoize per tick so the
		// per-recipient snapshot costs one DB round-trip set instead of one per group.
		$accessCodes = $this->accessCodesCache[$userId]
			??= CollectionAccessService::buildUserAccessCodes($userId);

		$documentKey = $userId . ':' . $documentId;
		$effectiveLevel = $this->documentLevelCache[$documentKey] ??= (
			DocumentAccessService::batchGetEffectiveLevels(
				[['id' => $documentId, 'collectionId' => $collectionId]],
				$accessCodes,
				$userId,
			)[$documentId] ?? DocumentAccessService::LEVEL_NONE
		);

		$collectionKey = $userId . ':' . $collectionId;
		$collectionLevel = $collectionId > 0
			? ($this->collectionLevelCache[$collectionKey]
				??= CollectionAccessService::getUserLevel($collectionId, $userId, $accessCodes))
			: CollectionAccessService::LEVEL_NONE;

		return [
			'canEdit' => $effectiveLevel >= DocumentAccessService::LEVEL_EDIT,
			'canEditCollection' => $collectionLevel >= CollectionAccessService::LEVEL_MANAGE,
			'canView' => $effectiveLevel >= DocumentAccessService::LEVEL_VIEW,
		];
	}
}
