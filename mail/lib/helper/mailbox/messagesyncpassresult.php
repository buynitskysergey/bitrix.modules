<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper\Mailbox;

/**
 * Outcome of a single pass over a list of message UIDs.
 *
 * The per-message lists are filled only in the fail-on-local-error mode, so a best-effort caller
 * gets the success flag and nothing else. A caller that marks a period as covered must treat a
 * non-empty deferred list as "not covered": those messages have no body yet. The same holds for an
 * incomplete list {@see markListIncomplete()}, which no per-message verdict can speak for.
 */
final class MessageSyncPassResult
{
	private bool $succeeded = true;
	private bool $stoppedOnTimeQuota = false;
	/** @var int[] */
	private array $deferredUids = [];
	/** @var int[] */
	private array $chargedUids = [];
	/** @var int[] */
	private array $completedUids = [];
	private int $messageClientErrorCount = 0;
	/** The lowest uid of a chunk whose list came back short of letters, null while every list was whole */
	private ?int $unvouchedUid = null;

	public function markFailed(): void
	{
		$this->succeeded = false;
	}

	/**
	 * The third outcome of a pass: the time of the hit ran out on a list that is still unfinished.
	 *
	 * It is not a success - the caller must not mark the period as covered, so the flag of the success
	 * goes down with it. It is not a failure of the dir either: nobody refused anything, the rest of the
	 * list is simply expected on the next run. A caller that keeps score per dir has to tell the two
	 * apart, because an unfinished list left by the clock must cost the dir neither an attempt nor the
	 * excuses it has saved up.
	 */
	public function markStoppedOnTimeQuota(): void
	{
		$this->stoppedOnTimeQuota = true;
		$this->succeeded = false;
	}

	/**
	 * The fourth outcome: a chunk whose fetched list lost entries as unusable letters. Which letter is
	 * missing cannot be told - an unsolicited response carries no uid and lands on the entry of the
	 * letter it overwrites - so nothing of the chunk is vouched for from its lowest uid up. The pass is
	 * not a success: the caller must move no cursor past that uid and must not call the period covered,
	 * while the letters below it stand as they always did.
	 */
	public function markListIncomplete(int $lowestUidOfChunk): void
	{
		$this->unvouchedUid = $this->unvouchedUid === null
			? $lowestUidOfChunk
			: min($this->unvouchedUid, $lowestUidOfChunk)
		;
		$this->succeeded = false;
	}

	public function isSucceeded(): bool
	{
		return $this->succeeded;
	}

	public function hasIncompleteList(): bool
	{
		return $this->unvouchedUid !== null;
	}

	/** The uid from which the pass vouches for nothing, null while every list of it was whole */
	public function getUnvouchedUid(): ?int
	{
		return $this->unvouchedUid;
	}

	public function hasStoppedOnTimeQuota(): bool
	{
		return $this->stoppedOnTimeQuota;
	}

	/**
	 * @param int $clientErrorCount - errors the mail server raised refusing this very message
	 * @param bool $spendsAttempt - true when the failure is the message's own: a lost link and a failure
	 *        of local storage tell nothing about the message, so they cost it no attempt
	 */
	public function deferUid(int $uid, int $clientErrorCount, bool $spendsAttempt): void
	{
		$this->deferredUids[] = $uid;
		$this->messageClientErrorCount += max($clientErrorCount, 0);

		if ($spendsAttempt)
		{
			$this->chargedUids[] = $uid;
		}
	}

	public function completeUid(int $uid): void
	{
		$this->completedUids[] = $uid;
	}

	/** @return int[] UIDs left for the next run because of a local failure */
	public function getDeferredUids(): array
	{
		return $this->deferredUids;
	}

	/** @return int[] deferred UIDs whose failure is their own, so an attempt of theirs is spent on it */
	public function getChargedUids(): array
	{
		return $this->chargedUids;
	}

	/** @return int[] UIDs with nothing left to do: the body arrived or the message was skipped */
	public function getCompletedUids(): array
	{
		return $this->completedUids;
	}

	/**
	 * Mail server errors already charged to the deferred messages. The client keeps its errors in a
	 * single collection for the whole connection, so a caller that watches that collection for a
	 * broken connection has to subtract these: a refusal to give one message is not a broken link.
	 */
	public function getMessageClientErrorCount(): int
	{
		return $this->messageClientErrorCount;
	}
}
