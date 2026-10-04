<?php

declare(strict_types=1);

namespace Bitrix\Crm\RepeatSale\Transcription;

/**
 * State of a call recording transcript.
 *
 * - None:    no transcription job, or a failed job that may still be retried - treated as absent;
 * - Pending: a job exists and is not finished yet;
 * - Ready:   a successful job with a non-empty transcription text;
 * - Skip:    a successful job that produced no usable text (empty / corrupted audio). Terminal:
 *            re-launching is pointless (the operation guard rejects a duplicate of a successful
 *            job), so it must never be retried nor keep the pipeline waiting.
 */
enum TranscriptState
{
	case None;
	case Pending;
	case Ready;
	case Skip;

	/**
	 * A terminal state carries no work for the gate or the collector: the transcript is either
	 * usable (Ready) or the job finished without usable text (Skip). A terminal call is never
	 * (re-)launched and never defers the pipeline.
	 */
	public function isTerminal(): bool
	{
		return $this === self::Ready || $this === self::Skip;
	}
}
