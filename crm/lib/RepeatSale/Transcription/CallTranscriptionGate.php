<?php

declare(strict_types=1);

namespace Bitrix\Crm\RepeatSale\Transcription;

use Bitrix\Crm\Feature;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\BaasManager;
use Bitrix\Crm\Integration\AI\ErrorCode;
use Bitrix\Crm\Integration\AI\Operation\Scenario;
use Bitrix\Crm\Integration\AI\Operation\TranscribeCallRecording;
use Bitrix\Crm\Integration\AI\Result as AiResult;
use Bitrix\Crm\RepeatSale\Logger;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Service gate that requests transcription of a client's not-yet-transcribed calls and signals
 * whether the pipeline may proceed to data collection.
 *
 * Result contract:
 * - success Result                       => Ready (proceed);
 * - Result with ErrorCode::OPERATION_IS_PENDING => NotReady (defer, at least one call is pending).
 *
 * The gate never throws into the caller and never blocks the pipeline: a failed launch of a single
 * call does not make the client permanently NotReady (partial success). Per-run counters are logged
 * for observability and attached to the Result data under the `counters` key.
 */
class CallTranscriptionGate
{
	private InScopeCallCollector $callCollector;
	private TranscriptStateResolver $stateResolver;
	private Logger $logger;

	public function __construct(
		?InScopeCallCollector $callCollector = null,
		?TranscriptStateResolver $stateResolver = null,
		?Logger $logger = null,
	)
	{
		$this->callCollector = $callCollector ?? new InScopeCallCollector();
		$this->stateResolver = $stateResolver ?? new TranscriptStateResolver();
		$this->logger = $logger ?? new Logger('RepeatSaleTranscription');
	}

	/**
	 * @param array<int, array{entityTypeId?: int, entityId?: int}> $clientIdentifiers
	 * @param bool $isFinalAttempt the buffer is on its last retry before dropping the item; when set,
	 *        the gate proceeds with whatever is already transcribed instead of deferring again, so the
	 *        downstream operation still runs (partial success) rather than being lost
	 */
	public function ensure(array $clientIdentifiers, int $baseDealId, bool $isFinalAttempt = false): Result
	{
		$counters = [
			'launched' => 0,
			'already_done' => 0,
			'skipped_ready' => 0,
			'skipped_empty' => 0,
			'skipped_pending' => 0,
			'skipped_unavailable' => 0,
			'launch_failed' => 0,
		];

		if (!$this->isFeatureEnabled())
		{
			return $this->buildReadyResult($counters);
		}

		if (!$this->isAiAvailable())
		{
			$counters['skipped_unavailable']++;
			$this->logger->info('ai unavailable, transcription skipped for base deal {baseDealId}', [
				'baseDealId' => $baseDealId,
			]);

			return $this->buildReadyResult($counters);
		}

		// The number of launches per client is bounded by the collector's per-client limit. The
		// overall time budget of a buffer element across retries is not throttled here - it stays
		// an open configuration question and is intentionally left out of this pass.
		$calls = $this->callCollector->collect($clientIdentifiers, $baseDealId);

		// Resolve every state up front, before the first launch. The first transcription launch writes
		// to b_crm_ai_queue and that ORM write clears the JobRepository runtime cache; resolving states
		// lazily inside the loop would therefore miss the collector's batch warm-up and hit the DB once
		// per call after the first launch.
		$states = [];
		foreach ($calls as $call)
		{
			$states[$call->activityId] = $this->stateResolver->resolveState($call->activityId);
		}

		$anyPending = false;
		foreach ($calls as $call)
		{
			$state = $states[$call->activityId];
			if ($state === TranscriptState::Ready)
			{
				$counters['skipped_ready']++;

				continue;
			}

			if ($state === TranscriptState::Skip)
			{
				// terminal empty transcript (silent / corrupted audio): nothing to transcribe and
				// nothing to wait for - skip without deferring the pipeline
				$counters['skipped_empty']++;

				continue;
			}

			if ($state === TranscriptState::Pending)
			{
				$counters['skipped_pending']++;
				$anyPending = true;

				continue;
			}

			try
			{
				$launchResult = $this->launchTranscription($call->activityId, $call->responsibleId);
			}
			catch (\Throwable $e)
			{
				// a single launch failure must not break the whole buffer pass (partial success)
				$counters['launch_failed']++;
				$this->logger->error('transcription launch threw for activity {activityId}: {message}', [
					'activityId' => $call->activityId,
					'dealId' => $call->dealId,
					'message' => $e->getMessage(),
				]);

				continue;
			}

			if ($launchResult->isSuccess() && $launchResult->isPending())
			{
				$counters['launched']++;
				$anyPending = true;
				$this->logger->info('transcription launched for activity {activityId}', [
					'activityId' => $call->activityId,
					'dealId' => $call->dealId,
				]);
			}
			elseif ($launchResult->isSuccess())
			{
				// launch succeeded without a pending job: the transcription completed right away
				// (e.g. stub mode or synchronous engine) - this is a success, not a failure, and
				// does not keep the client waiting
				$counters['already_done']++;
				$this->logger->info('transcription completed synchronously for activity {activityId}', [
					'activityId' => $call->activityId,
					'dealId' => $call->dealId,
				]);
			}
			elseif ($this->isAlreadyExists($launchResult))
			{
				$counters['skipped_pending']++;
				$anyPending = true;
				$this->logger->info('transcription job already exists for activity {activityId}', [
					'activityId' => $call->activityId,
					'dealId' => $call->dealId,
				]);
			}
			else
			{
				// other launch errors must not block the pipeline (partial success)
				$counters['launch_failed']++;
				$this->logger->error('transcription launch failed for activity {activityId}: {errors}', [
					'activityId' => $call->activityId,
					'dealId' => $call->dealId,
					'errors' => $launchResult->getErrorMessages(),
				]);
			}
		}

		$this->logSummary($baseDealId, $anyPending, $counters);

		if ($anyPending && $isFinalAttempt)
		{
			// last retry before the buffer drops the item: proceed with the transcripts that are
			// already ready instead of deferring forever - the downstream operation must run
			$this->logger->info(
				'transcription still pending on the final attempt for base deal {baseDealId}: proceeding with ready transcripts (partial success)',
				['baseDealId' => $baseDealId],
			);

			return $this->buildReadyResult($counters);
		}

		return $anyPending
			? $this->buildNotReadyResult($counters)
			: $this->buildReadyResult($counters)
		;
	}

	protected function isFeatureEnabled(): bool
	{
		return Feature::enabled(Feature\RepeatSaleTranscription::class);
	}

	protected function isAiAvailable(): bool
	{
		// hasPackage() is documented to throw (e.g. a missing BaasTokenService). This runs before the
		// per-call try/catch, so an escaping throwable would break the whole Consumer pass and violate
		// the gate's "never throws into the caller, safe fallback" contract. Treat any failure of the
		// availability probe as "AI unavailable" so the pipeline proceeds unchanged.
		try
		{
			return AIManager::isAiCallProcessingEnabled() && BaasManager::hasPackage();
		}
		catch (\Throwable $e)
		{
			$this->logger->error('failed to check AI availability: {message}', [
				'message' => $e->getMessage(),
			]);

			return false;
		}
	}

	/**
	 * Launches transcription on behalf of the call's responsible user. The buffer runs in a background
	 * agent with no authenticated user, so AbstractOperation would otherwise fall back to context user 0
	 * and TranscribeCallRecording::isAccessGranted() (requires a positive id with activity-edit rights)
	 * would deny every launch. The responsible of the call activity always has that access.
	 */
	protected function launchTranscription(int $activityId, int $userId): AiResult
	{
		return AIManager::launchCallRecordingTranscription(
			$activityId,
			Scenario::TRANSCRIBE_RECORD_SCENARIO,
			userId: $userId > 0 ? $userId : null,
			isManualLaunch: false,
			launchSource: TranscribeCallRecording::LAUNCH_SOURCE_REPEAT_SALE,
		);
	}

	private function isAlreadyExists(AiResult $result): bool
	{
		foreach ($result->getErrors() as $error)
		{
			if ($error->getCode() === ErrorCode::JOB_ALREADY_EXISTS)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string, int> $counters
	 */
	private function logSummary(int $baseDealId, bool $anyPending, array $counters): void
	{
		$this->logger->info('transcription gate summary for base deal {baseDealId}: {status}, {counters}', [
			'baseDealId' => $baseDealId,
			'status' => $anyPending ? 'not_ready' : 'ready',
			'counters' => $counters,
		]);
	}

	/**
	 * @param array<string, int> $counters
	 */
	private function buildReadyResult(array $counters): Result
	{
		return (new Result())->setData(['counters' => $counters]);
	}

	/**
	 * @param array<string, int> $counters
	 */
	private function buildNotReadyResult(array $counters): Result
	{
		$result = new Result();
		$result->addError(new Error('Call transcription is pending', ErrorCode::OPERATION_IS_PENDING));
		$result->setData(['counters' => $counters]);

		return $result;
	}
}
