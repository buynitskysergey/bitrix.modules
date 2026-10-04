<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\CallAssessment\Backfill;

use Bitrix\AI\Tokenizer\GPT;
use Bitrix\Crm\Copilot\CallAssessment\Controller\CopilotCallAssessmentController;
use Bitrix\Crm\Copilot\CallAssessment\Controller\CopilotCallAssessmentCriteriaController;
use Bitrix\Crm\Copilot\CallAssessment\Entity\CopilotCallAssessment;
use Bitrix\Crm\Copilot\CallScriptMaintenance\CandidatesRepository;
use Bitrix\Crm\Copilot\CallScriptMaintenance\Dispatcher;
use Bitrix\Crm\Copilot\CallScriptMaintenance\State;
use Bitrix\Crm\Copilot\CallScriptMaintenance\StateRepository;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Web\Json;

final class BackfillService
{
	public const TRANSCRIPTS_LIMIT = 50;
	public const TRANSCRIPTS_TOKEN_LIMIT = 50000;

	private const MIN_TRANSCRIPTS_FOR_AI = 10;
	private const INFLIGHT_OPTION_NAME = 'CALL_SCORING_V2_BACKFILL_INFLIGHT';
	private const TICK_LOCK_NAME = 'crm_call_scoring_v2_backfill_tick';

	private readonly CandidatesRepository $candidates;
	private readonly DefaultCriteriaProvider $defaultCriteria;
	private readonly Dispatcher $dispatcher;
	private readonly StateRepository $stateRepo;
	private ?string $loadedInflightJson = null;
	private ?GPT $tokenizer = null;
	/** @var array<int, int> */
	private array $transcriptTokenCache = [];

	public function __construct()
	{
		$this->candidates = new CandidatesRepository();
		$this->defaultCriteria = new DefaultCriteriaProvider();
		$this->dispatcher = Dispatcher::getInstance();
		$this->stateRepo = new StateRepository();
	}

	public function tick(): bool
	{
		$connection = Application::getConnection();
		if (!$connection->lock(self::TICK_LOCK_NAME))
		{
			return false;
		}

		try
		{
			$loaded = $this->loadInflight();
			$this->loadedInflightJson = Json::encode($loaded);

			$recentActivityIds = $this->candidates->findRecentTranscribedActivityIds(self::TRANSCRIPTS_LIMIT);
			$transcriptsById = $this->loadTranscripts($recentActivityIds, $loaded);

			$withoutCriteria = array_fill_keys($this->candidates->findCallAssessmentsWithoutCriteria(), true);

			$inflight = $this->processInflight($loaded, $transcriptsById, $withoutCriteria);

			$newCallAssessmentIds = array_values(
				array_diff(array_keys($withoutCriteria), array_keys($inflight)),
			);
			$this->launchPending(
				$newCallAssessmentIds,
				$inflight,
				$recentActivityIds,
				$transcriptsById,
				$withoutCriteria,
			);

			$pendingAfter = array_values(array_diff(array_keys($withoutCriteria), array_keys($inflight)));

			if (empty($inflight) && empty($pendingAfter))
			{
				$this->clearInflight();

				return true;
			}

			$this->saveInflight($inflight);

			return false;
		}
		finally
		{
			$connection->unlock(self::TICK_LOCK_NAME);
		}
	}

	/**
	 * @param array<int, array{jobId: int, remainingActivityIds: int[]}> $inflight
	 * @param array<int, string> $transcriptsById
	 * @param array<int, bool> $withoutCriteria
	 * @return array<int, array{jobId: int, remainingActivityIds: int[]}>
	 */
	private function processInflight(
		array $inflight,
		array $transcriptsById,
		array &$withoutCriteria,
	): array
	{
		if (empty($inflight))
		{
			return [];
		}

		$result = [];
		foreach ($inflight as $callAssessmentId => $entry)
		{
			$callAssessmentId = (int)$callAssessmentId;
			$jobId = (int)($entry['jobId'] ?? 0);
			$remaining = array_values(array_map('intval', (array)($entry['remainingActivityIds'] ?? [])));

			$status = $this->candidates->aiQueueStatus($jobId);
			if ($status === CandidatesRepository::AI_QUEUE_STATUS_PENDING)
			{
				$result[$callAssessmentId] = [
					'jobId' => $jobId,
					'remainingActivityIds' => $remaining,
				];

				continue;
			}

			if ($status !== CandidatesRepository::AI_QUEUE_STATUS_SUCCESS)
			{
				$this->fallbackToBasicIfStillEmpty($callAssessmentId, $withoutCriteria);

				continue;
			}

			if (empty($remaining))
			{
				$this->fallbackToBasicIfStillEmpty($callAssessmentId, $withoutCriteria);

				continue;
			}

			$next = $this->launchNextChunk($callAssessmentId, $remaining, $transcriptsById);
			if ($next === null)
			{
				$this->fallbackToBasicIfStillEmpty($callAssessmentId, $withoutCriteria);

				continue;
			}

			$result[$callAssessmentId] = $next;
		}

		return $result;
	}

	/**
	 * @param array<int, bool> $withoutCriteria mutated in-place
	 */
	private function fallbackToBasicIfStillEmpty(int $callAssessmentId, array &$withoutCriteria): void
	{
		if (!isset($withoutCriteria[$callAssessmentId]))
		{
			return;
		}

		AIManager::logger()->warning(
			'{date}: {class}: AI backfill failed for call assessment {callAssessment} — saving basic fallback criteria',
			['class' => self::class, 'callAssessment' => $callAssessmentId],
		);

		$this->saveDefaultCriteria($callAssessmentId, $this->defaultCriteria->getBasicCriteria(), $withoutCriteria);
	}

	/**
	 * @param int[] $pending
	 * @param array<int, array{jobId: int, remainingActivityIds: int[]}> $inflight
	 * @param int[] $recentActivityIds
	 * @param array<int, string> $transcriptsById
	 * @param array<int, bool> $withoutCriteria mutated in-place
	 */
	private function launchPending(
		array $pending,
		array &$inflight,
		array $recentActivityIds,
		array $transcriptsById,
		array &$withoutCriteria,
	): void
	{
		$controller = CopilotCallAssessmentController::getInstance();

		foreach ($pending as $callAssessmentId)
		{
			$callAssessment = $controller->getById($callAssessmentId);
			if ($callAssessment === null)
			{
				continue;
			}

			$defaultCriteria = $this->defaultCriteria->getCriteriaForCallAssessment($callAssessment);
			if (!empty($defaultCriteria))
			{
				$this->saveDefaultCriteria(
					$callAssessmentId,
					$defaultCriteria,
					$withoutCriteria,
					$this->defaultCriteria->getDescriptionForCallAssessment($callAssessment),
				);

				continue;
			}

			$entry = $this->startBackfillForCallAssessment(
				$callAssessment,
				$recentActivityIds,
				$transcriptsById,
				$withoutCriteria,
			);
			if ($entry !== null)
			{
				$inflight[$callAssessmentId] = $entry;
			}
		}
	}

	/**
	 * @param int[] $recentActivityIds
	 * @param array<int, string> $transcriptsById
	 * @param array<int, bool> $withoutCriteria
	 * @return array{jobId: int, remainingActivityIds: int[]}|null
	 */
	private function startBackfillForCallAssessment(
		CopilotCallAssessment $callAssessment,
		array $recentActivityIds,
		array $transcriptsById,
		array &$withoutCriteria,
	): ?array
	{
		if (count($recentActivityIds) < self::MIN_TRANSCRIPTS_FOR_AI)
		{
			$this->saveDefaultCriteria(
				$callAssessment->getId(),
				$this->defaultCriteria->getBasicCriteria(),
				$withoutCriteria,
			);

			return null;
		}

		$next = $this->launchNextChunk($callAssessment->getId(), $recentActivityIds, $transcriptsById);
		if ($next === null)
		{
			$this->saveDefaultCriteria(
				$callAssessment->getId(),
				$this->defaultCriteria->getBasicCriteria(),
				$withoutCriteria,
			);

			return null;
		}

		return $next;
	}

	/**
	 * @param int[] $activityIds
	 * @param array<int, string> $transcriptsById
	 * @return array{jobId: int, remainingActivityIds: int[]}|null
	 */
	private function launchNextChunk(
		int $callAssessmentId,
		array $activityIds,
		array $transcriptsById,
	): ?array
	{
		[$chunkIds, $remaining] = $this->splitByTokenLimit($activityIds, $transcriptsById);
		if (empty($chunkIds))
		{
			return null;
		}

		$transcripts = [];
		foreach ($chunkIds as $id)
		{
			$transcripts[] = $transcriptsById[$id];
		}

		$jobId = $this->dispatcher->launchEnrichCallAssessmentJob($callAssessmentId, $transcripts);
		if ($jobId <= 0)
		{
			return null;
		}

		$this->stateRepo->registerMaintenanceJob($jobId, [
			'type' => State::JOB_TYPE_BACKFILL_CALL_ASSESSMENT,
			'selectionIds' => [],
			'assessmentId' => $callAssessmentId,
		]);

		return [
			'jobId' => $jobId,
			'remainingActivityIds' => $remaining,
		];
	}

	/**
	 * @param int[] $recentActivityIds
	 * @param array<int, array{jobId: int, remainingActivityIds: int[]}> $inflight
	 * @return array<int, string>
	 */
	private function loadTranscripts(array $recentActivityIds, array $inflight): array
	{
		$activityIds = $recentActivityIds;
		foreach ($inflight as $entry)
		{
			foreach ((array)($entry['remainingActivityIds'] ?? []) as $id)
			{
				$activityIds[] = (int)$id;
			}
		}

		$activityIds = array_values(array_unique(array_filter(
			$activityIds,
			static fn($id) => (int)$id > 0,
		)));

		if (empty($activityIds))
		{
			return [];
		}

		return $this->candidates->loadTranscriptsForActivities($activityIds);
	}

	private function tokenCount(int $activityId, string $transcript): int
	{
		return $this->transcriptTokenCache[$activityId] ??= ($this->tokenizer ??= new GPT())->count($transcript);
	}

	/**
	 * @param int[] $activityIds
	 * @param array<int, string> $transcriptsById
	 * @return array{0: int[], 1: int[]}
	 */
	private function splitByTokenLimit(array $activityIds, array $transcriptsById): array
	{
		$chunk = [];
		$chunkTokens = 0;
		$consumed = 0;

		foreach ($activityIds as $id)
		{
			$consumed++;

			if (!isset($transcriptsById[$id]))
			{
				continue;
			}

			$tokens = $this->tokenCount($id, $transcriptsById[$id]);
			if ($tokens > self::TRANSCRIPTS_TOKEN_LIMIT)
			{
				AIManager::logger()->warning(
					'{date}: {class}: transcript for activity {activity} exceeds token limit ({tokens} > {limit}) — skipping',
					[
						'class' => self::class,
						'activity' => $id,
						'tokens' => $tokens,
						'limit' => self::TRANSCRIPTS_TOKEN_LIMIT,
					],
				);

				continue;
			}

			if (!empty($chunk) && $chunkTokens + $tokens > self::TRANSCRIPTS_TOKEN_LIMIT)
			{
				$consumed--;

				break;
			}

			$chunk[] = $id;
			$chunkTokens += $tokens;
		}

		$remaining = array_slice($activityIds, $consumed);

		return [$chunk, $remaining];
	}

	/**
	 * @param array<int, array{title: string, description: string}> $criteria
	 * @param array<int, bool> $withoutCriteria
	 */
	private function saveDefaultCriteria(
		int $callAssessmentId,
		array $criteria,
		array &$withoutCriteria,
		string $description = '',
	): void
	{
		$this->backfillDescriptionIfEmpty($callAssessmentId, $description);

		$controller = CopilotCallAssessmentCriteriaController::getInstance();
		$sort = 100;
		$savedCount = 0;
		foreach ($criteria as $criterion)
		{
			$result = $controller->add([
				'ASSESSMENT_ID' => $callAssessmentId,
				'TITLE' => $criterion['title'],
				'DESCRIPTION' => $criterion['description'],
				'SORT' => $sort,
			]);

			if ($result->isSuccess())
			{
				$savedCount++;
			}
			else
			{
				AIManager::logger()->error(
					'{date}: {class}: failed to save default criterion for call assessment {callAssessment}: {errors}',
					[
						'class' => self::class,
						'callAssessment' => $callAssessmentId,
						'errors' => implode('; ', $result->getErrorMessages()),
					],
				);
			}

			$sort += 100;
		}

		if ($savedCount > 0)
		{
			unset($withoutCriteria[$callAssessmentId]);
		}
	}

	private function backfillDescriptionIfEmpty(int $callAssessmentId, string $description): void
	{
		$description = trim($description);
		if ($callAssessmentId <= 0 || $description === '')
		{
			return;
		}

		$controller = CopilotCallAssessmentController::getInstance();
		$current = $controller->getById($callAssessmentId);
		if ($current === null || trim((string)$current->getDescription()) !== '')
		{
			return;
		}

		$result = $controller->updateDescription($callAssessmentId, $description);
		if (!$result->isSuccess())
		{
			AIManager::logger()->error(
				'{date}: {class}: failed to backfill default DESCRIPTION for call assessment {callAssessment}: {errors}',
				[
					'class' => self::class,
					'callAssessment' => $callAssessmentId,
					'errors' => implode('; ', $result->getErrorMessages()),
				],
			);
		}
	}

	/**
	 * @return array<int, array{jobId: int, remainingActivityIds: int[]}>
	 */
	private function loadInflight(): array
	{
		$raw = (string)Option::get('crm', self::INFLIGHT_OPTION_NAME, '');
		if ($raw === '')
		{
			return [];
		}

		try
		{
			$decoded = Json::decode($raw);
		}
		catch (\Throwable $e)
		{
			AIManager::logger()->warning(
				'{date}: {class}: corrupted backfill inflight option, resetting: {error}',
				['class' => self::class, 'error' => $e->getMessage()],
			);

			return [];
		}

		if (!is_array($decoded))
		{
			return [];
		}

		$result = [];
		foreach ($decoded as $callAssessmentId => $entry)
		{
			$callAssessmentId = (int)$callAssessmentId;
			$jobId = (int)($entry['jobId'] ?? 0);
			if ($callAssessmentId <= 0 || $jobId <= 0)
			{
				continue;
			}

			$remaining = array_values(array_filter(
				array_map('intval', (array)($entry['remainingActivityIds'] ?? [])),
				static fn($id) => $id > 0,
			));

			$result[$callAssessmentId] = [
				'jobId' => $jobId,
				'remainingActivityIds' => $remaining,
			];
		}

		return $result;
	}

	/**
	 * @param array<int, array{jobId: int, remainingActivityIds: int[]}> $inflight
	 */
	private function saveInflight(array $inflight): void
	{
		$encoded = Json::encode($inflight);

		if ($encoded === $this->loadedInflightJson)
		{
			return;
		}

		Option::set('crm', self::INFLIGHT_OPTION_NAME, $encoded);
	}

	private function clearInflight(): void
	{
		self::resetInflight();
	}

	public static function resetInflight(): void
	{
		Option::delete('crm', ['name' => self::INFLIGHT_OPTION_NAME]);
	}
}
