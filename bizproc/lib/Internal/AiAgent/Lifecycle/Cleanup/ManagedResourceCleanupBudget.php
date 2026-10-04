<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AiAgent\Lifecycle\Cleanup;

use Bitrix\Bizproc\Internal\Entity\AiAgent\ManagedAgentResourceType;
use Bitrix\Main\ArgumentOutOfRangeException;

/**
 * Row and time budget of one cleanup pass, shared by every participant of that pass.
 *
 * One synchronous call touches at most {@see self::DEFAULT_ROW_LIMIT} actual or service rows and lasts at
 * most {@see self::DEFAULT_PASS_SECONDS}. Both limits belong to the pass as a whole and are shared between
 * the participants, their reconciliations and the deletion of storage data, therefore the object is
 * deliberately mutable and is handed over instead of being rebuilt per participant. Tests replace both
 * values through the constructor, and a zero limit is a legal way to enter a pass that has nothing left.
 *
 * Time is measured by the monotonic clock of hrtime(), so a change of the system time neither extends nor
 * cuts a pass. The clock is read on demand and only the deadlines are kept.
 *
 * Rows and time are not interchangeable outcomes. A pass that ran out of rows has done a bounded portion of
 * work, which is a pending result of the participant and a continuation for the next pass. The own deadline
 * of a participant is about time only: it separates a participant that is waiting for an external dependency
 * in vain from one that is simply working through a large resource. It therefore covers the whole stretch of
 * work of one participant inside the pass and is opened once, no matter how many rows that participant walks.
 *
 * A pass is walked in two phases, and only the deletion phase is allowed to spend the whole budget. A
 * reconciliation is proportional to the resources that are still live, so a pass that let it spend everything
 * would never reach the deletion at all and the very set it walks would never shrink: the instance would stay
 * in the pending cleanup for good. The reconciliation phase is therefore bounded by
 * {@see self::DEFAULT_RECONCILE_SHARE} of both the rows and the time of the pass, which leaves the rest of the
 * budget to the deletion and makes every pass reach it.
 */
final class ManagedResourceCleanupBudget
{
	public const DEFAULT_ROW_LIMIT = 500;

	public const DEFAULT_PASS_SECONDS = 5.0;

	/**
	 * Share of the rows and of the time of a pass the reconciliations of all its types may spend together.
	 */
	public const DEFAULT_RECONCILE_SHARE = 0.5;

	private const NANOSECONDS_IN_SECOND = 1000000000;

	private readonly int $passDeadline;

	private readonly int $reconcileRowLimit;

	private readonly int $reconcileDeadline;

	private bool $reconcilePhase = false;

	private ?ManagedAgentResourceType $participant = null;

	private ?int $participantDeadline = null;

	private int $consumedRows = 0;

	/**
	 * @param int $rowLimit actual or service rows the whole pass may read or delete
	 * @param float $passSeconds monotonic seconds the whole pass may last
	 * @param float $reconcileShare part of the pass the reconciliations may spend, between nothing and all of it
	 * @throws ArgumentOutOfRangeException when a limit is negative, the number of seconds is not finite or the
	 *  share is outside its bounds
	 */
	public function __construct(
		private readonly int $rowLimit = self::DEFAULT_ROW_LIMIT,
		float $passSeconds = self::DEFAULT_PASS_SECONDS,
		float $reconcileShare = self::DEFAULT_RECONCILE_SHARE,
	)
	{
		if ($rowLimit < 0)
		{
			throw new ArgumentOutOfRangeException('rowLimit', 0);
		}

		if ($reconcileShare < 0 || $reconcileShare > 1 || !is_finite($reconcileShare))
		{
			throw new ArgumentOutOfRangeException('reconcileShare', 0, 1);
		}

		$startedAt = hrtime(true);

		$this->passDeadline = $startedAt + self::toNanoseconds($passSeconds, 'passSeconds');
		$this->reconcileDeadline = $startedAt + self::toNanoseconds($passSeconds * $reconcileShare, 'passSeconds');
		$this->reconcileRowLimit = (int)floor($rowLimit * $reconcileShare);
	}

	/**
	 * Enters the reconciliation phase, whose share of the pass is the only part a reconciliation may spend.
	 */
	public function enterReconcilePhase(): void
	{
		$this->reconcilePhase = true;
	}

	/**
	 * Enters the deletion phase, which is the phase a pass is in until it says otherwise and the only one that
	 * may spend the whole budget.
	 */
	public function enterCleanupPhase(): void
	{
		$this->reconcilePhase = false;
	}

	/**
	 * Rows the pass may still touch in its current phase, which is also the size limit of the next select of
	 * registered rows or of storage ids the participant is about to read.
	 */
	public function getRemainingRows(): int
	{
		$limit = $this->reconcilePhase ? $this->reconcileRowLimit : $this->rowLimit;

		return max(0, $limit - $this->consumedRows);
	}

	/**
	 * Accounts the actual or service rows a participant has read or deleted.
	 *
	 * @throws ArgumentOutOfRangeException when the number of rows is negative
	 */
	public function consumeRows(int $rows): void
	{
		if ($rows < 0)
		{
			throw new ArgumentOutOfRangeException('rows', 0);
		}

		$this->consumedRows += $rows;
	}

	/**
	 * Tells whether the current phase of the pass has to stop and be continued later: no rows or no time left.
	 */
	public function isExhausted(): bool
	{
		$deadline = $this->reconcilePhase ? $this->reconcileDeadline : $this->passDeadline;

		return $this->getRemainingRows() === 0 || hrtime(true) >= $deadline;
	}

	/**
	 * Opens the own deadline of the participant that is starting to work, at most as long as the pass has
	 * left. Until a participant asks for one, its deadline is the deadline of the pass.
	 *
	 * The deadline is opened once for the whole stretch of work of that participant: a participant is entered
	 * with a reconciliation and then walks its registered rows one by one, so a call of the participant that
	 * already owns the deadline keeps it. A deadline every row reopens could never be reached at all, and the
	 * failure of a participant that waits for an external dependency in vain would never be reported.
	 *
	 * @param ManagedAgentResourceType $participant type the calling participant cleans up
	 * @param float $seconds monotonic seconds this participant may spend before it has to give an answer
	 * @throws ArgumentOutOfRangeException when the number of seconds is negative or not finite
	 */
	public function startParticipantDeadline(ManagedAgentResourceType $participant, float $seconds): void
	{
		// The argument is checked on every call, so a call that keeps an already opened deadline hides no
		// unusable value.
		$deadline = hrtime(true) + self::toNanoseconds($seconds, 'seconds');

		if ($this->participant === $participant)
		{
			return;
		}

		$this->participant = $participant;
		$this->participantDeadline = min($deadline, $this->passDeadline);
	}

	/**
	 * Tells whether the participant is out of its own deadline, hence out of time to reach progress.
	 *
	 * A participant that has made no progress by then returns a failed result, which is charged with the retry
	 * delay of the background continuation, as a blocked continuation before the deadline already is.
	 */
	public function isParticipantOverdue(): bool
	{
		return hrtime(true) >= ($this->participantDeadline ?? $this->passDeadline);
	}

	private static function toNanoseconds(float $seconds, string $parameter): int
	{
		if ($seconds < 0 || !is_finite($seconds))
		{
			throw new ArgumentOutOfRangeException($parameter, 0);
		}

		return (int)round($seconds * self::NANOSECONDS_IN_SECOND);
	}
}
