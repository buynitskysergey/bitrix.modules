<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Agent\Document;

use Bitrix\Note\Internal\Access\Service\CollectionAccessService;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Service\Bulk\BoundaryReparenter;
use Bitrix\Note\Internal\Service\Collaboration\PushNotificationService;
use Bitrix\Note\Public\Command\MoveAllOwnedInCollectionCommand;
use Bitrix\Note\Public\Command\MoveSelectedDocumentsCommand;

/**
 * Durable, resumable continuation of ONE author-scoped move whose set exceeded
 * {@see MoveAllOwnedInCollectionCommand::MOVE_SYNC_THRESHOLD}. The head of the set travelled in the
 * request; everything after it travels here.
 *
 * There is no separate queue: b_agent IS the queue, one row per unfinished move, in the standard
 * agent idiom. The row's NAME holds the whole state — `run(userId, collectionId, targetCollectionId,
 * targetParentId, cursor, remaining)`, six integers and no id list — and the string a tick returns
 * replaces
 * that NAME (CAgent::ExecuteAgents), so a pass saves its resume point by returning itself with a new
 * cursor. Returning '' deletes the row, which is how a finished move unregisters.
 *
 * What makes a tick cheap: a node that has moved leaves the set by itself, its COLLECTION_ID is no
 * longer the source one, so every tick simply asks again which of the caller's top nodes are still
 * in the source base. The cursor is not a pagination device and not a boundary either — it is a hint
 * that keeps one sweep from circling on the nodes it may not move. A sweep that runs out of nodes
 * while the caller's documents are still in the base starts over from zero, and `remaining` — what
 * the previous sweep left behind — is what tells a sweep that moved nothing to give up instead.
 *
 * Rights are re-checked here and not carried over from the request: both the set and the ACL may
 * have changed between the synchronous part and the tail, and the move command asks about MANAGE on
 * every document it touches anyway.
 *
 * AddAgent dedups by NAME, so a repeated request before the first tick collapses into the same row.
 * Once the cursor has advanced the name differs, and the second request opens its own row with a
 * zero cursor — harmless, the same way it is for the ACL agent: the nodes the first row has already
 * moved are out of the set, so the second row converges on what is left and deletes itself.
 */
final class OwnedMoveContinuationAgent
{
	// How many top nodes go in one move call. Each of them is its own transaction with a branch lock
	// and an ACL sync, so the group is small and the tick keeps taking groups until the watchdog.
	public const NODE_CHUNK = 10;

	private const WATCHDOG_SECONDS = 10.0;

	// Interval between ticks while a move still has a tail. Not a background heartbeat: a row only
	// exists while its move is unfinished, so an idle portal never waits this out.
	private const AGENT_INTERVAL = 300;

	/**
	 * Continue one author-scoped move, resuming after $cursor.
	 *
	 * @param int $targetParentId 0 means the root of the target knowledge base.
	 * @param int $cursor last top node already handled; 0 starts a fresh pass.
	 * @param int $remaining what the previous sweep left in the source base; 0 means no sweep has
	 *                      finished yet. Read only when a sweep runs out of nodes, to tell a sweep
	 *                      that moved nothing from one that is still making progress.
	 * @param PushNotificationService|null $pushService Injection seam for tests; CAgent evaluates the
	 *                                                  expression with the six ids only.
	 * @param float|null $watchdogSeconds Injection seam for tests, same story.
	 * @param DocumentRepository|null $repository Injection seam for tests, same story.
	 * @return string '' when the move is done (deletes this agent), otherwise the resume expression
	 */
	public static function run(
		int $userId = 0,
		int $collectionId = 0,
		int $targetCollectionId = 0,
		int $targetParentId = 0,
		int $cursor = 0,
		int $remaining = 0,
		?PushNotificationService $pushService = null,
		?float $watchdogSeconds = null,
		?DocumentRepository $repository = null,
	): string
	{
		if ($userId <= 0 || $collectionId <= 0 || $targetCollectionId <= 0)
		{
			return '';
		}

		try
		{
			return self::pass(
				$userId,
				$collectionId,
				$targetCollectionId,
				$targetParentId,
				$cursor,
				$remaining,
				$pushService,
				$watchdogSeconds ?? self::WATCHDOG_SECONDS,
				$repository ?? new DocumentRepository(),
			);
		}
		catch (\Throwable $e)
		{
			self::logError('OwnedMoveContinuationAgent: ' . $e->getMessage());

			// Retry the same resume point on the next tick: a node that did move has left the set, so a
			// re-run neither moves it twice nor loses the ones that did not.
			return self::expression(
				$userId,
				$collectionId,
				$targetCollectionId,
				$targetParentId,
				$cursor,
				$remaining,
			);
		}
	}

	/**
	 * Idempotent registration, called by {@see OwnedMoveContinuationScheduler}. The migrations
	 * deliberately never register this agent — a portal with nothing deferred carries no row at all.
	 *
	 * A fresh row is due at once - the core writes CURRENT_DATE into NEXT_EXEC - so the tail starts on
	 * the next agent pass instead of waiting out an interval: a hit, or a cron pass where the portal
	 * runs agents from crontab. $existError = false keeps the deduplicated case from pushing a
	 * CAdminException into $APPLICATION on every request.
	 */
	public static function schedule(int $userId, int $collectionId, int $targetCollectionId, ?int $targetParentId): void
	{
		if ($userId <= 0 || $collectionId <= 0 || $targetCollectionId <= 0)
		{
			return;
		}

		\CAgent::AddAgent(
			self::expression($userId, $collectionId, $targetCollectionId, (int)$targetParentId, 0, 0),
			'note',
			'N',
			self::AGENT_INTERVAL,
			'',
			'Y',
			'',
			100,
			false,
			false,
		);
	}

	/**
	 * The b_agent NAME for a given resume point. Every value is cast to int on the way in: the string
	 * is eval'd by the agent framework, so nothing but integers may reach it.
	 */
	public static function expression(
		int $userId,
		int $collectionId,
		int $targetCollectionId,
		int $targetParentId,
		int $cursor,
		int $remaining = 0,
	): string
	{
		return self::class . '::run('
			. (int)$userId . ', '
			. (int)$collectionId . ', '
			. (int)$targetCollectionId . ', '
			. (int)$targetParentId . ', '
			. (int)$cursor . ', '
			. (int)$remaining
			. ');'
		;
	}

	/**
	 * @return string '' when nothing of the move is left, otherwise the resume expression
	 */
	private static function pass(
		int $userId,
		int $collectionId,
		int $targetCollectionId,
		int $targetParentId,
		int $cursor,
		int $remaining,
		?PushNotificationService $pushService,
		float $watchdogSeconds,
		DocumentRepository $repository,
	): string
	{
		$abandon = static fn(string $reason, ?int $leftBehind = null): string => self::abandon(
			$repository,
			$userId,
			$collectionId,
			$targetCollectionId,
			$reason,
			$leftBehind,
		);

		// An archived target base takes nothing in, and the move command would throw on every tick.
		// There is nothing left to converge to, so the row goes instead of retrying forever.
		if (!CollectionAccessService::collectionAcceptsWrites($targetCollectionId))
		{
			return $abandon('the target knowledge base is archived');
		}

		// Taking documents out is a write into the source base too, and an archived one takes none. Work
		// begun before the archiving is no exception, or the guard would be bypassed by simply waiting.
		if (!CollectionAccessService::collectionAcceptsWrites($collectionId))
		{
			return $abandon('the source knowledge base is archived');
		}

		// MANAGE on the target is asked about once, by the request, and this row outlives it: revoked
		// in between, the tail must not arrive anyway. Nothing restores the right by itself, so the row
		// goes rather than retries.
		if (!CollectionAccessService::userHasLevel($targetCollectionId, $userId, CollectionAccessService::LEVEL_MANAGE))
		{
			return $abandon('MANAGE on the target knowledge base is gone');
		}

		$start = microtime(true);
		$moved = false;
		$pending = self::pendingTopNodes($repository, $userId, $collectionId, $cursor);

		while (!empty($pending['topNodeIds']))
		{
			if ($moved && (microtime(true) - $start) > $watchdogSeconds)
			{
				break;
			}

			$chunk = array_slice($pending['topNodeIds'], 0, self::NODE_CHUNK);
			(new MoveSelectedDocumentsCommand(
				$userId,
				self::selectionUnder($chunk, $pending['ownedIds'], $pending['ancestorsById']),
				$targetCollectionId,
				$targetParentId > 0 ? $targetParentId : null,
			))->run();

			$pending['topNodeIds'] = array_slice($pending['topNodeIds'], count($chunk));
			$cursor = (int)end($chunk);
			$moved = true;
		}

		if ($moved)
		{
			self::pushRefetch($collectionId, $targetCollectionId, $pushService);
		}

		if (!empty($pending['topNodeIds']))
		{
			return self::expression(
				$userId,
				$collectionId,
				$targetCollectionId,
				$targetParentId,
				$cursor,
				$remaining,
			);
		}

		// Running out of nodes is not the same as running out of work: a node this sweep could not take
		// stays in the base with an id the cursor has gone past, and so does one a concurrent edit lifts
		// to the top level behind the cursor. So a sweep that ends with the caller's documents still here
		// starts over from zero instead of dropping the tail, and only a sweep that moved none of what
		// was left gives up — that is what keeps the row from circling forever.
		$left = $repository->countLiveOwnedCollectionDocuments($collectionId, $userId);
		if ($left <= 0)
		{
			return '';
		}

		if ($remaining > 0 && $left >= $remaining)
		{
			return $abandon('a sweep from the start of the set moved nothing that was left behind', $left);
		}

		return self::expression($userId, $collectionId, $targetCollectionId, $targetParentId, 0, $left);
	}

	/**
	 * Deletes the row, and says so in the journal when the set has not run out. The user was told the
	 * move goes on in the background, so a tail dropped instead of finished may not disappear without
	 * a trace; a drained set is the ordinary end of a move and is worth no entry.
	 */
	private static function abandon(
		DocumentRepository $repository,
		int $userId,
		int $collectionId,
		int $targetCollectionId,
		string $reason,
		?int $leftBehind = null,
	): string
	{
		// Counted in the database: a tail exists because the set was large, and the number is all this
		// needs — carrying tens of thousands of ids into PHP to call count() on them is not.
		$leftBehind ??= $repository->countLiveOwnedCollectionDocuments($collectionId, $userId);
		if ($leftBehind > 0)
		{
			self::logError(sprintf(
				'OwnedMoveContinuationAgent: dropped the move of user %d from collection %d to collection %d'
					. ' with %d document(s) left behind - %s',
				$userId,
				$collectionId,
				$targetCollectionId,
				$leftBehind,
				$reason,
			));
		}

		return '';
	}

	/**
	 * What of the caller's set is still in the source base. Read once per tick and then drawn down in
	 * memory chunk by chunk: the enumeration walks every document of mine in the base, so its cost is
	 * amortised over a whole tick's worth of writes rather than paid per chunk. The nodes the cursor
	 * left behind are not asked about again.
	 *
	 * @return array{ownedIds: int[], ancestorsById: array<int, int[]>, topNodeIds: int[]}|array{}
	 */
	private static function pendingTopNodes(
		DocumentRepository $repository,
		int $userId,
		int $collectionId,
		int $cursor,
	): array
	{
		$ownedIds = $repository->getLiveOwnedCollectionDocumentIds($collectionId, $userId);
		if (empty($ownedIds))
		{
			return [];
		}

		$ancestorsById = $repository->getAncestorIdsMap($ownedIds);
		$topNodeIds = BoundaryReparenter::topNodes($ownedIds, $ancestorsById);
		sort($topNodeIds);

		$topNodeIds = array_values(array_filter($topNodeIds, static fn(int $id): bool => $id > $cursor));

		return empty($topNodeIds)
			? []
			: ['ownedIds' => $ownedIds, 'ancestorsById' => $ancestorsById, 'topNodeIds' => $topNodeIds]
		;
	}

	/**
	 * The selection one move call gets: the chosen top nodes and every node of the set below them.
	 * Handing over the top nodes alone would leave their own descendants behind as outsiders, and the
	 * move would pull them out of the branch instead of taking them along.
	 *
	 * @param int[] $topNodeIds
	 * @param int[] $ownedIds
	 * @param array<int, int[]> $ancestorsById
	 * @return int[]
	 */
	private static function selectionUnder(array $topNodeIds, array $ownedIds, array $ancestorsById): array
	{
		$tops = array_fill_keys($topNodeIds, true);

		$selection = $topNodeIds;
		foreach ($ownedIds as $id)
		{
			if (isset($tops[$id]))
			{
				continue;
			}

			foreach ($ancestorsById[$id] ?? [] as $ancestorId)
			{
				if (isset($tops[(int)$ancestorId]))
				{
					$selection[] = $id;

					break;
				}
			}
		}

		return $selection;
	}

	private static function pushRefetch(
		int $collectionId,
		int $targetCollectionId,
		?PushNotificationService $pushService,
	): void
	{
		$pushService ??= new PushNotificationService();
		$collectionIds = array_unique([$collectionId, $targetCollectionId]);

		// No initiator skip: this work is out-of-band, so the initiator's own client is the one waiting
		// to hear that the move has finished.
		$pushService->dispatchAfterCommit(static function () use ($pushService, $collectionIds): void {
			foreach ($collectionIds as $id)
			{
				$pushService->sendToCollection(
					$id,
					'documentMove',
					['collectionId' => $id, 'requestRefetch' => true],
				);
			}
		});
	}

	private static function logError(string $message): void
	{
		\CEventLog::Add([
			'SEVERITY' => \CEventLog::SEVERITY_ERROR,
			'AUDIT_TYPE_ID' => 'NOTE_OWNED_MOVE_CONTINUATION_ERROR',
			'MODULE_ID' => 'note',
			'DESCRIPTION' => $message,
		]);
	}
}
