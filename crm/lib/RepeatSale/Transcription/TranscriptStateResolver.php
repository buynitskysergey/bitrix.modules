<?php

declare(strict_types=1);

namespace Bitrix\Crm\RepeatSale\Transcription;

use Bitrix\AI\Tokenizer\TokenizerInterface;
use Bitrix\Crm\Integration\AI\Dto\TranscribeCallRecordingPayload;
use Bitrix\Crm\Integration\AI\JobRepository;

/**
 * Single source of truth about the transcription state of a call activity.
 *
 * Reads through the {@see JobRepository} runtime cache and classifies the state; it does not
 * decide whether transcription may be launched (that is up to the operation guard) and does not
 * read transcript texts (that stays the responsibility of the data-collector strategy).
 */
class TranscriptStateResolver
{
	private JobRepository $jobRepository;
	private ?TokenizerInterface $tokenizer;

	public function __construct(?JobRepository $jobRepository = null, ?TokenizerInterface $tokenizer = null)
	{
		$this->jobRepository = $jobRepository ?? JobRepository::getInstance();
		$this->tokenizer = $tokenizer;
	}

	/**
	 * Preloads transcript states for the given activities in a single query so that the following
	 * per-activity resolveState() calls hit the warm JobRepository cache (no per-call query).
	 *
	 * @param int[] $activityIds
	 */
	public function warmCache(array $activityIds): void
	{
		$this->jobRepository->warmTranscribeCacheForActivities($activityIds);
	}

	public function resolveState(int $activityId): TranscriptState
	{
		$result = $this->jobRepository->getTranscribeCallRecordingResultByActivity($activityId);
		if ($result === null)
		{
			return TranscriptState::None;
		}

		if ($result->isPending())
		{
			return TranscriptState::Pending;
		}

		if ($result->isSuccess())
		{
			// a successful job is terminal and cannot be re-launched (the operation guard rejects a
			// duplicate of a successful job): non-empty text => Ready, empty text (silent / corrupted
			// audio) => Skip. It must not fall back to None here, otherwise the gate would keep
			// trying to re-launch it and treat the resulting JOB_ALREADY_EXISTS as pending forever.
			$payload = $result->getPayload();

			return $payload instanceof TranscribeCallRecordingPayload && ($payload->transcription ?? '') !== ''
				? TranscriptState::Ready
				: TranscriptState::Skip
			;
		}

		// failed job - treat as absent; the operation guard decides whether a retry is still allowed
		return TranscriptState::None;
	}

	/**
	 * Token count of the ready transcript text for the activity, or 0 when the transcript is not
	 * ready. Used by the budget projection (layer B) to account for text that is already available.
	 * Reads through the same warm JobRepository cache as resolveState().
	 */
	public function getReadyTranscriptTokens(int $activityId): int
	{
		if ($this->resolveState($activityId) !== TranscriptState::Ready)
		{
			return 0;
		}

		$payload = $this->jobRepository
			->getTranscribeCallRecordingResultByActivity($activityId)
			?->getPayload()
		;

		if ($payload instanceof TranscribeCallRecordingPayload)
		{
			return $this->getTokenizer()->count($payload->transcription);
		}

		return 0;
	}

	private function getTokenizer(): TokenizerInterface
	{
		return $this->tokenizer ??= (new TranscriptionBudget())->getTokenizer();
	}
}
