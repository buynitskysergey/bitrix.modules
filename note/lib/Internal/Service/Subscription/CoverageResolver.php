<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Subscription;

use Bitrix\Note\Internal\Model\SubscriptionTable;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Repository\SubscriptionRepository;
use Bitrix\Note\Internal\Service\Notification\NotificationShippingPolicy;

/**
 * The single implementation of "is this object covered by notifications for this
 * user, and how". Serves the bell of one document, a whole page of rows and the recipient walk of
 * SubscriptionResolver, so the inheritance rule exists in one place only.
 *
 * Priority of sources: direct row > "with nested" row on an ancestor > subscription on the
 * knowledge base. A direct row in MODE_MUTED is a negative override: it is reported alongside the
 * inherited coverage it suppresses, so the bell can show both.
 *
 * `notified` answers a narrower question than the rest of the state: not "is this object covered"
 * but "does anything actually reach this user here". Delivery resolves its recipients from the
 * direct rows of the article in the modes NotificationShippingPolicy ships and walks no
 * inheritance, so the flag says exactly that. A `subtree` row, an `all` row and coverage from an
 * ancestor or from the knowledge base are still reported as they are - the interface needs them to
 * draw a legacy subscription - but they promise nothing and read as `notified` false. The
 * knowledge-base scope ships no mode at all, so there `notified` is never true.
 *
 * Rights are not checked here - visibility is the calling layer's job, same split as
 * SubscriptionResolver: losing access must not silently delete a subscription row.
 */
final class CoverageResolver
{
	public const SOURCE_SUBTREE = 'subtree';
	public const SOURCE_COLLECTION = 'collection';

	/**
	 * @var array<int, array{anchorIds: int[], anchorCollectionIds: array<int, true>}>
	 */
	private array $userAnchorCache = [];

	/**
	 * $cacheUserAnchors keeps the two user-level reads of the ancestor walk (the "with nested"
	 * anchors and their knowledge bases) for the lifetime of THIS instance. Opt-in on purpose: it is
	 * only sound while the instance answers reads of one request and never outlives a subscription
	 * write. Callers that hold a resolver across writes must leave it off.
	 */
	public function __construct(
		private readonly SubscriptionRepository $subscriptionRepository = new SubscriptionRepository(),
		private readonly DocumentRepository $documentRepository = new DocumentRepository(),
		private readonly bool $cacheUserAnchors = false,
	) {}

	/**
	 * Coverage state per document.
	 *
	 * $items maps a document id to the collection the coverage question is asked about - normally
	 * the document's own knowledge base. The ancestor walk is skipped for documents whose
	 * collection holds no anchor of this user, which is only sound because a document and its
	 * ancestors share one COLLECTION_ID; a caller that passes some other collection gets the
	 * collection half of the verdict for it but must not rely on the ancestor half.
	 *
	 * @param array<int, int> $items documentId => collectionId (0 when unknown)
	 * @return array<int, array{
	 *   mode: ?string, subscribed: bool, muted: bool,
	 *   inherited: bool, inheritedSource: ?string, notified: bool
	 * }> one state per requested document id
	 */
	public function resolve(int $userId, array $items): array
	{
		$normalizedItems = [];
		foreach ($items as $documentId => $collectionId)
		{
			$documentId = (int)$documentId;
			if ($documentId > 0)
			{
				$normalizedItems[$documentId] = max(0, (int)$collectionId);
			}
		}

		if (empty($normalizedItems))
		{
			return [];
		}

		$documentIds = array_keys($normalizedItems);
		$directModes = $this->subscriptionRepository->getUserModes($userId, SubscriptionTable::SCOPE_DOCUMENT, $documentIds);
		$collectionModes = $this->subscriptionRepository->getUserModes(
			$userId,
			SubscriptionTable::SCOPE_COLLECTION,
			array_values($normalizedItems),
		);
		$coveredByAncestor = $this->resolveAncestorCoverage($userId, $normalizedItems);

		$states = [];
		foreach ($normalizedItems as $documentId => $collectionId)
		{
			$mode = $directModes[$documentId] ?? null;
			$subscribed = in_array(
				$mode,
				[SubscriptionTable::MODE_SELF, SubscriptionTable::MODE_SUBTREE, SubscriptionTable::MODE_ALL],
				true,
			);
			$muted = ($mode === SubscriptionTable::MODE_MUTED);

			$inheritedSource = null;
			if (isset($coveredByAncestor[$documentId]))
			{
				$inheritedSource = self::SOURCE_SUBTREE;
			}
			elseif (($collectionModes[$collectionId] ?? null) === SubscriptionTable::MODE_ALL)
			{
				$inheritedSource = self::SOURCE_COLLECTION;
			}

			$inherited = ($inheritedSource !== null);

			$states[$documentId] = [
				'mode' => $mode,
				'subscribed' => $subscribed,
				'muted' => $muted,
				'inherited' => $inherited,
				'inheritedSource' => $inheritedSource,
				'notified' => self::isDelivered(SubscriptionTable::SCOPE_DOCUMENT, $mode),
			];
		}

		return $states;
	}

	/**
	 * Coverage state per knowledge base. A knowledge base is only ever subscribed
	 * directly (MODE_ALL) - there is nothing above it to inherit from and no mute on it - and
	 * MODE_ALL is not shipped, so `notified` is false on every row this method answers.
	 *
	 * @param int[] $collectionIds
	 * @return array<int, array{
	 *   mode: ?string, subscribed: bool, muted: bool,
	 *   inherited: bool, inheritedSource: ?string, notified: bool
	 * }>
	 */
	public function resolveCollections(int $userId, array $collectionIds): array
	{
		$normalizedIds = array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $collectionIds),
			static fn(int $id): bool => $id > 0,
		)));
		if (empty($normalizedIds))
		{
			return [];
		}

		$modes = $this->subscriptionRepository->getUserModes($userId, SubscriptionTable::SCOPE_COLLECTION, $normalizedIds);

		$states = [];
		foreach ($normalizedIds as $collectionId)
		{
			$mode = $modes[$collectionId] ?? null;
			$subscribed = ($mode === SubscriptionTable::MODE_ALL);

			$states[$collectionId] = [
				'mode' => $mode,
				'subscribed' => $subscribed,
				'muted' => false,
				'inherited' => false,
				'inheritedSource' => null,
				'notified' => self::isDelivered(SubscriptionTable::SCOPE_COLLECTION, $mode),
			];
		}

		return $states;
	}

	/**
	 * Whether delivery reaches a user through a row of this mode, asked of the DIRECT row alone:
	 * NotificationRecipientService reads direct rows in the shipped modes and walks nothing above
	 * them, so a mode outside that list and coverage that has no row of its own deliver nothing
	 * whatever the rest of the state looks like.
	 */
	private static function isDelivered(string $scope, ?string $mode): bool
	{
		return $mode !== null && in_array($mode, NotificationShippingPolicy::shippedModes($scope), true);
	}

	/**
	 * The chain coverage walks up: full walk to the root with no archived and no
	 * collection-boundary cutoff. Single entry point for everyone who needs that chain,
	 * including the recipient resolver.
	 *
	 * @param int[] $documentIds
	 * @return array<int, int[]> documentId => ancestor ids ordered [parent, ..., root]
	 */
	public function resolveAncestorIds(array $documentIds): array
	{
		$normalizedIds = array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $documentIds),
			static fn(int $id): bool => $id > 0,
		)));
		if (empty($normalizedIds))
		{
			return [];
		}

		// One document reads one cached row per level; a set trades that cache for one query
		// per level, which only pays off from the second document on.
		if (count($normalizedIds) === 1)
		{
			$documentId = $normalizedIds[0];

			return [$documentId => $this->documentRepository->getAncestorIds($documentId)];
		}

		return $this->documentRepository->getAncestorIdsMap($normalizedIds);
	}

	/**
	 * The nearest ancestor whose "with nested" subscription covers the document, or null.
	 * Only the source signature of the bell needs it - plain coverage is a flag in resolve().
	 */
	public function findCoveringAncestorId(int $userId, int $documentId): ?int
	{
		if ($userId <= 0 || $documentId <= 0)
		{
			return null;
		}

		$ancestorIds = $this->resolveAncestorIds([$documentId])[$documentId] ?? [];
		if (empty($ancestorIds))
		{
			return null;
		}

		$subscribedIds = $this->subscriptionRepository->findSubscribedEntityIds(
			$userId,
			SubscriptionTable::SCOPE_DOCUMENT,
			$ancestorIds,
			SubscriptionTable::MODE_SUBTREE,
		);
		if (empty($subscribedIds))
		{
			return null;
		}

		// $ancestorIds is nearest-first, so the first match is the closest covering ancestor.
		$subscribedSet = array_flip($subscribedIds);
		foreach ($ancestorIds as $ancestorId)
		{
			if (isset($subscribedSet[$ancestorId]))
			{
				return $ancestorId;
			}
		}

		return null;
	}

	/**
	 * @param array<int, int> $items documentId => collectionId
	 * @return array<int, true> documents covered by a "with nested" subscription on an ancestor
	 */
	private function resolveAncestorCoverage(int $userId, array $items): array
	{
		['anchorIds' => $anchorIds, 'anchorCollectionIds' => $anchorCollectionIds] = $this->resolveUserAnchors($userId);
		if (empty($anchorIds))
		{
			return [];
		}

		$frontier = [];
		foreach ($items as $documentId => $collectionId)
		{
			if (isset($anchorCollectionIds[$collectionId]))
			{
				$frontier[] = $documentId;
			}
		}

		if (empty($frontier))
		{
			return [];
		}

		$anchorSet = array_fill_keys($anchorIds, true);
		$covered = [];
		foreach ($this->resolveAncestorIds($frontier) as $documentId => $ancestorIds)
		{
			foreach ($ancestorIds as $ancestorId)
			{
				if (isset($anchorSet[$ancestorId]))
				{
					$covered[$documentId] = true;
					break;
				}
			}
		}

		return $covered;
	}

	/**
	 * The user-level half of the ancestor walk: identical for every batch of the same request, so a
	 * page that refills over several keyset windows reads it once instead of once per window.
	 *
	 * @return array{anchorIds: int[], anchorCollectionIds: array<int, true>}
	 */
	private function resolveUserAnchors(int $userId): array
	{
		if ($this->cacheUserAnchors && isset($this->userAnchorCache[$userId]))
		{
			return $this->userAnchorCache[$userId];
		}

		$anchorIds = $this->subscriptionRepository->findUserSubtreeAnchorIds($userId);
		$anchors = [
			'anchorIds' => $anchorIds,
			'anchorCollectionIds' => empty($anchorIds)
				? []
				: array_fill_keys(array_values($this->documentRepository->getCollectionIds($anchorIds)), true)
			,
		];

		if ($this->cacheUserAnchors)
		{
			$this->userAnchorCache[$userId] = $anchors;
		}

		return $anchors;
	}
}
