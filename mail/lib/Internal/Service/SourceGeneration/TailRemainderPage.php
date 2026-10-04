<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\SourceGeneration;

/**
 * One page of the remainder of a transfer, as {@see TailRemainderQuery} hands it out.
 *
 * The cursor is reported apart from the letters because a call may walk a long way
 * without finding a single one: everything of that stretch is already on the new
 * source. A caller that took the last letter of the page for its cursor would walk
 * that stretch again on every call and never reach the end of the mailbox.
 */
final readonly class TailRemainderPage
{
	/**
	 * @param int[] $messageIds Logical messages of the page, oldest first.
	 * @param int $cursor The message id the next call continues after.
	 * @param bool $isExhausted The walk reached the end of the mailbox, so this pass is over.
	 */
	public function __construct(
		public array $messageIds,
		public int $cursor,
		public bool $isExhausted,
	)
	{
	}
}
