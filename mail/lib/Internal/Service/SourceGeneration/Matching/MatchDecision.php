<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\SourceGeneration\Matching;

use Bitrix\Mail\Internals\SourceGenerationMatchTable;

/**
 * The decision of the matcher for one incoming uid (ALG-01).
 *
 * MATCHED carries the existing logical message the uid must be linked to. NEW and
 * AMBIGUOUS carry no message yet: the importer creates a separate logical message
 * and reports it back, and candidates of an AMBIGUOUS decision are never merged.
 */
final readonly class MatchDecision
{
	public const REASON_MIGRATOR_REFERENCE = 'MIGRATOR_REFERENCE';
	public const REASON_MESSAGE_ID = 'MESSAGE_ID';
	public const REASON_CONTENT = 'CONTENT';
	public const REASON_NO_CANDIDATE = 'NO_CANDIDATE';
	public const REASON_NO_SURVIVOR = 'NO_SURVIVOR';
	public const REASON_SEVERAL_CANDIDATES = 'SEVERAL_CANDIDATES';
	public const REASON_INCOMPLETE = 'INCOMPLETE_CANDIDATE';
	public const REASON_UNCORROBORATED = 'UNCORROBORATED';
	public const REASON_STORED = 'STORED';

	/**
	 * The letter was put onto the new source by the tail append stage itself, and the
	 * server named the coordinates it gave it. Nothing about such a letter is decided: we
	 * know which local message it is because we sent it {@see TailAppendService}.
	 */
	public const REASON_APPENDED = 'APPENDED';

	/**
	 * The same letter of the tail append stage, on a source that named no coordinates for it.
	 * The identity comes from the record the stage wrote before the append, which the one time
	 * mark inside the letter leads to
	 * {@see \Bitrix\Mail\Internal\Service\SourceGeneration\TailMarkService}.
	 */
	public const REASON_TAIL_MARK = 'TAIL_MARK';

	/**
	 * The letter was imported as a new one and the active source delivered it afterwards, so the
	 * decision named a letter the mailbox did not hold yet at the time it was made. The
	 * reconciliation of the hand over gave the placement the delivered letter instead
	 * {@see \Bitrix\Mail\Internal\Service\SourceGeneration\LateDeliveryReconciler}.
	 */
	public const REASON_LATE_DELIVERY = 'LATE_DELIVERY';

	/**
	 * @param int[] $candidates Diagnostics of an ambiguous decision, never a merge instruction.
	 */
	public function __construct(
		public string $state,
		public int $messageId,
		public string $reasonCode,
		public array $candidates = [],
	)
	{
	}

	public function isMatched(): bool
	{
		return $this->state === SourceGenerationMatchTable::STATE_MATCHED;
	}

	public function isAmbiguous(): bool
	{
		return $this->state === SourceGenerationMatchTable::STATE_AMBIGUOUS;
	}

	/**
	 * The final local identity of this uid is already known, so repeating the
	 * import of the same uid reuses the decision instead of deciding again.
	 */
	public function isTerminal(): bool
	{
		return $this->messageId > 0;
	}
}
