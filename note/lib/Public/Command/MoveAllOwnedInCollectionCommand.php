<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Command;

use Bitrix\Main\Result;
use Bitrix\Note\Internal\Access\Service\CollectionAccessService;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Service\Bulk\BulkAccessAggregator;
use Bitrix\Note\Internal\Service\Bulk\OwnedMoveDeferralSink;
use Bitrix\Note\Internal\Service\Bulk\SelectionResolver;
use Bitrix\Note\Internal\Service\Collaboration\PushNotificationService;
use Bitrix\Note\Internal\Service\Document\Position\PositionService;
use Bitrix\Note\Internal\Service\DomainEventPublisher;
use Bitrix\Note\Internal\Service\History\EventLogService;
use Bitrix\Note\Internal\Service\Link\BacklinkLifecycleNotifier;

/**
 * "Move all my articles": every live document of one knowledge base the calling user authored goes
 * to another base. The set is resolved on the server over the whole base and plays the part of the
 * selection — everything else is inherited from {@see MoveSelectedDocumentsCommand}, so kinship
 * between the caller's own nodes survives the trip while somebody else's documents stay behind on
 * the nearest node that remains.
 *
 * Node by node, not as one set: the set-oriented {@see MoveAllInCollectionCommand} applies only
 * where the whole base travels and nothing can be orphaned. There is no cap either — a set resolved
 * on the server is never capped, so the limit flag of the resolve shape is always false.
 *
 * MANAGE is still required on every document, as it is for the author-scoped archive and delete;
 * moving into the same base is allowed, because lifting all of one's own articles to its root is a
 * meaningful thing to ask for and already works one document at a time.
 *
 * The set has no ceiling either: what does not fit in the request goes to an {@see OwnedMoveDeferralSink}
 * and the answer says so with `deferredTail`. The cut is made before the selection is assembled and
 * its boundary cleared, so neither of those two walks the deferred part; the hand-over itself waits
 * until the request has gone through. Without a sink the command is synchronous whatever the size of
 * the set.
 */
class MoveAllOwnedInCollectionCommand extends MoveSelectedDocumentsCommand
{
	/**
	 * How many top nodes travel in the request; the rest is handed to the sink. Counted in top nodes
	 * and not in documents, because each of them is a transaction of its own with a branch lock and an
	 * ACL sync — an order of magnitude smaller than a threshold counted in rows.
	 */
	public const MOVE_SYNC_THRESHOLD = 50;

	private readonly int $collectionId;
	private readonly ?OwnedMoveDeferralSink $deferralSink;
	private bool $tailHeldBack = false;

	public function __construct(
		int $collectionId,
		int $targetCollectionId,
		?int $targetParentId,
		int $userId,
		?SelectionResolver $selectionResolver = null,
		?BulkAccessAggregator $accessAggregator = null,
		?PositionService $positionService = null,
		?DocumentRepository $repository = null,
		?PushNotificationService $pushService = null,
		?EventLogService $eventLogService = null,
		?DomainEventPublisher $eventPublisher = null,
		?BacklinkLifecycleNotifier $backlinkNotifier = null,
		?OwnedMoveDeferralSink $deferralSink = null,
	)
	{
		// No root ids: the set is resolved, not handed in.
		parent::__construct(
			$userId,
			[],
			$targetCollectionId,
			$targetParentId,
			$selectionResolver,
			$accessAggregator,
			$positionService,
			$repository,
			$pushService,
			$eventLogService,
			$eventPublisher,
			$backlinkNotifier,
		);

		$this->collectionId = $collectionId;
		$this->deferralSink = $deferralSink;
	}

	/**
	 * The answer of the base command plus the one thing it cannot carry: whether a tail was left for
	 * the background. The endpoint prints it, so the flag is always there, true or false.
	 */
	protected function execute(): Result
	{
		// Taking one's articles out is a write into the source base, so its state decides it: an
		// archived base is read-only, and an author-scoped move gets no exception to that.
		CollectionAccessService::assertCollectionAcceptsWrites($this->collectionId);

		$result = parent::execute();

		// The tail is handed over only once the request itself has gone through. Deciding to hold it
		// back happens early, before the selection is assembled, but assembling and clearing the
		// boundary are transactions of their own and may still fail — and a request that failed has to
		// mean nothing happened. A row registered ahead of that would outlive the failure and take the
		// WHOLE set away on its first tick, not the part the request did not reach.
		$deferredTail = $this->tailHeldBack && $result->isSuccess();
		if ($deferredTail)
		{
			$this->deferralSink?->enqueue(
				$this->userId,
				$this->collectionId,
				$this->targetCollectionId,
				$this->targetParentId,
			);
		}

		$result->setData($result->getData() + ['deferredTail' => $deferredTail]);

		return $result;
	}

	/**
	 * Cuts the set before the base command builds anything out of it: everything past the threshold
	 * keeps its branch exactly as it stands and travels when the background gets to it, so a set of any
	 * size costs the request the same handful of transactions. Only the decision is taken here; the
	 * tail is handed to the sink by {@see execute()} once the request has succeeded.
	 */
	protected function narrowMovingTops(array $movingTopIds): array
	{
		if ($this->deferralSink === null || count($movingTopIds) <= self::MOVE_SYNC_THRESHOLD)
		{
			return $movingTopIds;
		}

		$this->tailHeldBack = true;

		return array_slice($movingTopIds, 0, self::MOVE_SYNC_THRESHOLD);
	}

	protected function resolveMoveSet(): array
	{
		$ids = $this->repository->getLiveOwnedCollectionDocumentIds($this->collectionId, $this->userId);

		return [
			'ids' => $ids,
			'byCollection' => empty($ids) ? [] : [$this->collectionId => $ids],
			// A server-resolved set has no cap to exceed and no "with nested" toggle to describe.
			'limitExceeded' => false,
			'hasNested' => false,
			'rootsMeta' => $this->repository->getMetaByIds($ids, DocumentRepository::MOVE_META_SELECT),
		];
	}
}
