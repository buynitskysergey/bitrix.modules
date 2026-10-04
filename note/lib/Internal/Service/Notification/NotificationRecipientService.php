<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Notification;

use Bitrix\Note\Internal\Access\PortalAdmin;
use Bitrix\Note\Internal\Access\Service\CollectionAccessService;
use Bitrix\Note\Internal\Access\Service\DocumentAccessService;
use Bitrix\Note\Internal\Entity\History\Event;
use Bitrix\Note\Internal\Model\EventTable;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Service\History\EventVisibilityPolicy;
use Bitrix\Note\Internal\Service\History\SubscriptionResolver;

/**
 * [P7.T2 / ALG-03] Turns "who is subscribed" into "who may actually be notified of
 * this dedup'd event group right now" — the send-time gate the drainer calls once
 * per group. Only document-scope groups are resolved: SCOPE=collection events are
 * not written in v1 (see SDD P7), so there is nothing else to look up here yet.
 */
final class NotificationRecipientService
{
	public function __construct(
		private readonly SubscriptionResolver $subscriptionResolver = new SubscriptionResolver(),
		private readonly DocumentRepository $documentRepository = new DocumentRepository(),
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
	public function resolve(Event $group): array
	{
		if ($group->getScope() !== EventTable::SCOPE_DOCUMENT)
		{
			return [];
		}

		$documentId = $group->getEntityId();
		$document = $this->documentRepository->getMetaById($documentId, ['ID', 'COLLECTION_ID']);
		if ($document === null)
		{
			return [];
		}

		$collectionId = (int)$document->getCollectionId();

		$initiatorId = $group->getUserId();
		$candidates = array_filter(
			$this->subscriptionResolver->resolveDocumentRecipients($documentId, $collectionId),
			static fn(int $userId): bool => $userId !== $initiatorId,
		);

		if (empty($candidates))
		{
			return [];
		}

		$eventType = $group->getEventType();
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

		// A subscriber recurs across every dedup group of the same batch (subtree/collection
		// subscription), and access is immutable within a single tick — memoize per tick so the
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
