<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Provider;

use Bitrix\Main\Config\Feature;
use Bitrix\Note\Internal\Access\Service\CollectionAccessService;
use Bitrix\Note\Internal\Access\Service\DocumentAccessService;
use Bitrix\Note\Internal\Config\Feature\DocumentNotifications;
use Bitrix\Note\Internal\Exceptions\AccessDeniedException;
use Bitrix\Note\Internal\Exceptions\DocumentNotFoundException;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Service\Subscription\CoverageResolver;
use Bitrix\Note\Internal\Service\Subscription\SubscriptionListService;

/**
 * Subscription state for the bell control, and the read side of the subscriptions block: one page of
 * the articles the caller subscribed to themselves.
 *
 * Right for the bell is plain LEVEL_VIEW on the document (mirrors ViewProvider); the collection half
 * of the state is only reported when the viewer can also see the collection; otherwise "this
 * document and all nested" is not a meaningful choice for them and reporting it would leak the
 * collection's subscriber-state to a document-only grantee.
 *
 * The page is personal, so the provider resolves the access codes of that very user and hands them
 * to the list service together with the pagination parameters; assembling the rows (titles, rights,
 * coverage, chevron) is the service's job and stays batched there.
 */
final class SubscriptionProvider
{
	public function __construct(
		private readonly DocumentProvider $documentProvider = new DocumentProvider(),
		private readonly CoverageResolver $coverageResolver = new CoverageResolver(),
		private readonly DocumentRepository $documentRepository = new DocumentRepository(),
		private readonly CollectionProvider $collectionProvider = new CollectionProvider(),
		private readonly SubscriptionListService $listService = new SubscriptionListService(),
	) {}

	/**
	 * @param array{id: int}|null $afterCursor
	 * @return array{items: array<int, array<string, mixed>>, nextCursor: array{id: int}|null}
	 */
	public function getPage(
		int $userId,
		int $limit = SubscriptionListService::DEFAULT_LIMIT,
		?array $afterCursor = null,
	): array
	{
		if ($userId <= 0)
		{
			return ['items' => [], 'nextCursor' => null];
		}

		return $this->listService->listPage(
			$userId,
			CollectionAccessService::buildUserAccessCodes($userId),
			min(
				SubscriptionListService::MAX_LIMIT,
				$limit > 0 ? $limit : SubscriptionListService::DEFAULT_LIMIT,
			),
			$afterCursor,
		);
	}

	/**
	 * Whether the caller has anything the subscriptions block would show. Asked once while the page
	 * is rendered, so the sidebar can offer the notifications mode without a request of its own.
	 * Without it the block hides itself whenever the favorites list is empty and the mode becomes
	 * unreachable.
	 *
	 * With the portal feature off there is no such mode to offer, so the answer is `false` and the
	 * database is not asked at all.
	 */
	public function hasSubscriptions(int $userId): bool
	{
		if ($userId <= 0 || !Feature::isEnabled(DocumentNotifications::class))
		{
			return false;
		}

		return $this->listService->hasAnySubscription(
			$userId,
			CollectionAccessService::buildUserAccessCodes($userId),
		);
	}

	/**
	 * `subscribed`/`mode` describe a DIRECT self/subtree subscription on this document.
	 * `inherited` is true when the document is covered by a subtree subscription on an ancestor or a
	 * subscription on its collection (same rule the notification resolver uses) — the bell then reads
	 * as active without a direct row. `muted` is a per-document negative override that suppresses that
	 * inherited coverage. The three are mutually exclusive on the direct row (one row per user+doc).
	 *
	 * @return array{
	 *   document: array{subscribed: bool, mode: ?string, muted: bool, inherited: bool, inheritedSource: ?string, inheritedTitle: ?string},
	 *   collection: array{subscribed: bool}|null
	 * }
	 * @throws DocumentNotFoundException
	 * @throws AccessDeniedException current user lacks LEVEL_VIEW on the document
	 */
	public function getState(int $userId, int $documentId, ?int $collectionId = null): array
	{
		$ownership = $this->documentProvider->getOwnershipInfo($documentId);
		if ($ownership === null)
		{
			throw new DocumentNotFoundException();
		}

		$documentCollectionId = (int)$ownership['collectionId'];
		$snapshot = DocumentAccessService::getCurrentUserSnapshot(
			$documentId,
			$documentCollectionId,
			allowStates: [DocumentAccessService::STATE_ARCHIVED],
		);
		if (!$snapshot['canView'])
		{
			throw new AccessDeniedException();
		}

		$resolvedCollectionId = ($collectionId !== null && $collectionId > 0) ? $collectionId : $documentCollectionId;

		// Coverage itself (direct row, ancestors, knowledge base) is the resolver's rule, asked here
		// for a single document. The document's OWN collection goes into the item: that is the
		// collection its ancestors live in, which is what the ancestor walk is narrowed by.
		$coverage = $this->coverageResolver->resolve($userId, [$documentId => $documentCollectionId])[$documentId];

		// Collection subscription row - needed both for the source signature (below) and for the
		// VIEW-gated `collection` block. Read independently of that gate: coverage is a fact of the
		// row's existence, not of whether we may report the collection state to this viewer.
		$collectionState = $resolvedCollectionId > 0
			? ($this->coverageResolver->resolveCollections($userId, [$resolvedCollectionId])[$resolvedCollectionId] ?? null)
			: null;

		// The caller may ask about a collection other than the document's own, so the knowledge-base
		// half of the verdict is taken from the row they asked about; the ancestor half stands as the
		// resolver reported it and keeps winning the source (nearest rule for the UI).
		$inheritedSource = $coverage['inheritedSource'] === CoverageResolver::SOURCE_SUBTREE
			? CoverageResolver::SOURCE_SUBTREE
			: (($collectionState !== null && $collectionState['subscribed']) ? CoverageResolver::SOURCE_COLLECTION : null);

		// `inheritedTitle` names that source so the bell can say WHICH section covers the document
		// ("в составе раздела «…»") instead of a vague "parent document".
		$inheritedTitle = null;
		if ($inheritedSource === CoverageResolver::SOURCE_SUBTREE)
		{
			$ancestorId = $this->coverageResolver->findCoveringAncestorId($userId, $documentId);
			$meta = $ancestorId !== null ? $this->documentRepository->getMetaById($ancestorId, ['ID', 'TITLE']) : null;
			$inheritedTitle = $meta !== null ? (string)$meta->getTitle() : null;
		}
		elseif ($inheritedSource === CoverageResolver::SOURCE_COLLECTION)
		{
			$collection = $this->collectionProvider->getById($resolvedCollectionId);
			$inheritedTitle = $collection !== null ? (string)$collection->getName() : null;
		}

		// `subscribed` counts MODE_ALL as positive here while the previous predicate listed only
		// self/subtree; the two agree because the scope/mode whitelist never lets a document row hold
		// MODE_ALL. Same the other way round for `collection` below - there MODE_ALL is the only mode.
		$document = [
			'subscribed' => $coverage['subscribed'],
			'mode' => $coverage['subscribed'] ? $coverage['mode'] : null,
			'muted' => $coverage['muted'],
			'inherited' => $inheritedSource !== null,
			'inheritedSource' => $inheritedSource,
			'inheritedTitle' => $inheritedTitle,
		];

		// The caller may ask about a collection other than the document's own
		// (e.g. a shared/moved document), so the VIEW check must target
		// $resolvedCollectionId itself, not $documentCollectionId's snapshot.
		$collection = null;
		if ($resolvedCollectionId > 0 && CollectionAccessService::currentUserHasLevel($resolvedCollectionId, CollectionAccessService::LEVEL_VIEW))
		{
			$collection = ['subscribed' => $collectionState !== null && $collectionState['subscribed']];
		}

		return [
			'document' => $document,
			'collection' => $collection,
		];
	}
}
