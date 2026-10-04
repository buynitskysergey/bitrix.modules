<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Agent\Access;

use Bitrix\Note\Internal\Access\Service\DocumentAccessService;
use Bitrix\Note\Internal\Service\Access\SubtreeAclReconciler;
use Bitrix\Note\Internal\Service\Collaboration\PushNotificationService;

/**
 * [P4.T6] Durable, resumable background reconciliation of ONE subtree source whose WIDEN pass
 * exceeded {@see SubtreeAclReconciler::WIDEN_SYNC_THRESHOLD}. Only WIDENING is deferred here —
 * narrowing and lowering are always applied synchronously on the request thread.
 *
 * There is no separate queue: b_agent IS the queue, one row per pending source, in the standard
 * agent idiom (same as the import stepper carrying its session id). The row's NAME holds the whole
 * state — `run(sourceId, collectionId, cursor)` — and the string a tick returns replaces that NAME
 * (CAgent::ExecuteAgents), so a pass saves its resume point simply by returning itself with a new
 * cursor. Returning '' deletes the row, which is how a finished source unregisters.
 *
 * A revoke is always synchronous, so the agent must never re-materialise one from a snapshot it took
 * before it landed. Three things enforce that: the target is re-read before every chunk, a sweep runs
 * at the start of each tick, and another runs before the tick returns, whichever way it exits.
 *
 * Consequences worth keeping in mind when editing:
 * - Sources cannot clobber each other: each owns its own row. There is no shared structure to
 *   read-modify-write, hence no lost updates and no lock.
 * - {@see schedule()} runs inside the caller's grant transaction, so the registration commits or
 *   rolls back together with the grant it belongs to.
 * - A re-grant while a pass is in flight yields a DIFFERENT name (cursor 0), i.e. a second row that
 *   re-runs the subtree from the start. Deliberate: widening is an idempotent UPSERT against the
 *   source's CURRENT target, so the fresh pass picks up subjects the in-flight one had already
 *   scrolled past. Further re-grants collapse into that second row while it still sits at cursor 0
 *   (AddAgent dedups by NAME) — once it has advanced, the next re-grant opens another cursor-0 row.
 *   Bounded in practice by how many re-grants fit inside one pass, and each row converges and
 *   deletes itself; there is no unique index on b_agent.NAME to lean on for more than that.
 */
final class SubtreeAclReconcileAgent
{
	// A tick re-enumerates the subtree once and then widens chunk after chunk until the watchdog stops
	// it, so the enumeration is amortised over a whole tick's worth of writes rather than paid per
	// chunk. It cannot be replaced by keyset pagination — see descendantIdsForSource().
	private const WATCHDOG_SECONDS = 10.0;
	private const NODE_CHUNK = 500;

	// Interval between ticks while a source still has a tail. Not a background heartbeat: a row only
	// exists while its source is unfinished, so an idle portal never waits this out.
	private const AGENT_INTERVAL = 300;

	/**
	 * Reconcile one source's subtree, resuming after $cursor.
	 *
	 * @param int $cursor last descendant id already widened; 0 starts a fresh pass.
	 * @param PushNotificationService|null $pushService Injection seam for tests; CAgent evaluates the
	 *                                                  expression with the three ids only.
	 * @return string '' when the source is done (deletes this agent), otherwise the resume expression
	 */
	public static function run(
		int $sourceId = 0,
		int $collectionId = 0,
		int $cursor = 0,
		?PushNotificationService $pushService = null
	): string
	{
		if ($sourceId <= 0 || $collectionId <= 0)
		{
			return '';
		}

		// No deferral sink: this reconciler widens synchronously (it must never re-defer to itself).
		$reconciler = new SubtreeAclReconciler();

		try
		{
			return self::pass($reconciler, $sourceId, $collectionId, $cursor, $pushService);
		}
		catch (\Throwable $e)
		{
			self::logError('SubtreeAclReconcileAgent: ' . $e->getMessage());

			// Retry the same resume point on the next tick; widening is idempotent, so re-running a
			// partially applied chunk neither duplicates nor drops rows.
			return self::expression($sourceId, $collectionId, $cursor);
		}
	}

	/**
	 * Idempotent registration, called by {@see SubtreeAclReconcileScheduler} when a widen is deferred.
	 * The migrations deliberately never register this agent — a portal with nothing deferred carries
	 * no row at all.
	 *
	 * A fresh row gets NEXT_EXEC = now (CAgent::Add), so the pass starts on the next hit instead of
	 * waiting out an interval. AddAgent dedups by NAME, and $existError = false keeps the duplicate
	 * case from pushing a CAdminException into $APPLICATION on every grant.
	 */
	public static function schedule(int $sourceId, int $collectionId): void
	{
		if ($sourceId <= 0 || $collectionId <= 0)
		{
			return;
		}

		\CAgent::AddAgent(
			self::expression($sourceId, $collectionId, 0),
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
	public static function expression(int $sourceId, int $collectionId, int $cursor): string
	{
		return self::class . '::run(' . (int)$sourceId . ', ' . (int)$collectionId . ', ' . (int)$cursor . ');';
	}

	/**
	 * @return string '' when the whole subtree has converged, otherwise the resume expression
	 */
	private static function pass(
		SubtreeAclReconciler $reconciler,
		int $sourceId,
		int $collectionId,
		int $cursor,
		?PushNotificationService $pushService
	): string
	{
		// Every tick, not only a fresh pass. A revoke that landed while the PREVIOUS tick was widening
		// could have been re-materialised from that tick's snapshot after the revoke's own synchronous
		// delete had already run; this sweep is what removes those rows. Cheap and idempotent: one
		// addressed DELETE per subject that is no longer in the target.
		$reconciler->synchroniseRemovals($sourceId);

		$target = $reconciler->targetForSource($sourceId);
		$descendants = $reconciler->descendantIdsForSource($sourceId, $collectionId);
		if (empty($target) || empty($descendants))
		{
			return '';
		}

		$count = count($descendants);
		$index = 0;
		while ($index < $count && $descendants[$index] <= $cursor)
		{
			$index++;
		}

		$start = microtime(true);
		$materialised = [];
		$materialisedSubjects = [];
		$targetEmptied = false;

		while ($index < $count)
		{
			// Re-read before every chunk instead of trusting the snapshot taken above: a revoke that
			// lands mid-tick must not be undone by the chunks that follow it. One indexed lookup on
			// IX_NOTE_DOC_ACCESS_SOURCE per 500 nodes.
			$target = $reconciler->targetForSource($sourceId);
			if (empty($target))
			{
				// The source lost its entire target mid-tick. There is nothing left to widen ever
				// again, so this pass is over — resuming would only spend a tick to learn the same.
				$targetEmptied = true;

				break;
			}

			$chunk = array_slice($descendants, $index, self::NODE_CHUNK);
			$reconciler->widenNodes($sourceId, $target, $chunk);
			$materialised = array_merge($materialised, $chunk);
			// Accumulated per chunk rather than read off $target at the end: the last read may have
			// come back empty, and the cascade still has to name the subjects the earlier chunks wrote.
			$materialisedSubjects += array_fill_keys(array_keys($target), true);
			$index += count($chunk);
			$cursor = (int)end($chunk);

			if ((microtime(true) - $start) > self::WATCHDOG_SECONDS)
			{
				break;
			}
		}

		// One cascade per tick: the recipients' open clients would otherwise learn nothing about a
		// deferred widen until a page reload.
		if (!empty($materialised))
		{
			DocumentAccessService::notifyMaterialisedSubtree(
				$collectionId,
				$materialised,
				array_keys($materialisedSubjects),
				$pushService,
			);
		}

		// Sweep before EVERY exit, not only on convergence. Without it a revoke racing the last chunk
		// would survive: the chunk re-inserts from a target read microseconds earlier, the revoke's own
		// delete has already run, and the next tick that would notice is a whole AGENT_INTERVAL away —
		// or, for a finished source, never. A revoke landing after this point removes its rows itself.
		$reconciler->synchroniseRemovals($sourceId);

		if (!$targetEmptied && $index < $count)
		{
			return self::expression($sourceId, $collectionId, $cursor);
		}

		return '';
	}

	private static function logError(string $message): void
	{
		\CEventLog::Add([
			'SEVERITY' => \CEventLog::SEVERITY_ERROR,
			'AUDIT_TYPE_ID' => 'NOTE_SUBTREE_ACL_RECONCILE_ERROR',
			'MODULE_ID' => 'note',
			'DESCRIPTION' => $message,
		]);
	}
}
