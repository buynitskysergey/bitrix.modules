<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\CallAssessment\Summary;

use Bitrix\Crm\Copilot\AiQualityAssessment\Entity\AiQualityAssessmentTable;
use Bitrix\Crm\Copilot\CallAssessment\CriteriaLoader;
use Bitrix\Crm\Copilot\CallAssessment\Entity\CopilotCallAssessmentTable;
use Bitrix\Crm\Integration\AI\Model\QueueTable;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Json;

/**
 * Builds the AI operation input (DTO-01) with a manager's call-scoring statistics
 * for the closed 7-day window [D-7d, D], where D is the reference date.
 *
 * Per-criterion "met" verdicts are NOT stored in b_crm_ai_quality_assessment
 * (it keeps only the aggregate ASSESSMENT and the criteria definitions in
 * CRITERIA_DATA). The only source of per-call, per-criterion verdicts is the
 * ScoreCallV2 job result persisted in b_crm_ai_queue.RESULT as
 * {"criteriaScores":[{"criterion_name","met","comment"}],"recommendations":"..."}
 * (serialized ScoreCallV2Payload DTO, so keys are camelCase property names) where a
 * missing/null "met" means the criterion was not applicable to that call.
 *
 * Each call_summaries[].summary is the ScoreCallV2 "recommendations" of the matching
 * job; calls without recommendations are skipped.
 *
 * The returned structure uses snake_case keys because it is fed directly into
 * the prompt payload markers (see Operation\Payload\Payload\ManagerSummary).
 */
final class ManagerSummaryDataProvider
{
	public const WINDOW_DAYS = 7;
	private const CALL_SUMMARIES_LIMIT = 50;

	// Size budget for call_summaries, the dominant free-text block of the AI input. The target
	// model (Qwen3-32B) has a ~32k-token context and rejects overflowing requests, so "heavy"
	// managers must not blow the budget. Sizing uses a rough ~4-chars-per-token heuristic.
	// Per-item cap ~800 chars ≈ ~200 tokens: longer texts are truncated on a character boundary.
	private const CALL_SUMMARY_MAX_CHARS = 800;
	// Cumulative cap across the whole block ~32k chars ≈ ~8k tokens, leaving ample room for the
	// prompt template, scripts_stats, the system role and the model's own response under 32k.
	private const CALL_SUMMARIES_BUDGET_CHARS = 32000;

	private CriteriaLoader $criteriaLoader;

	public function __construct(?CriteriaLoader $criteriaLoader = null)
	{
		$this->criteriaLoader = $criteriaLoader ?? new CriteriaLoader();
	}

	/**
	 * @return array{
	 *     agent_name: string,
	 *     period: string,
	 *     total_calls: int,
	 *     scripts_stats: list<array{
	 *         script_id: int,
	 *         script_name: string,
	 *         calls_count: int,
	 *         criteria: list<array{criterion_id: int, criterion_name: string, score_pct: int, applicable_count: int}>
	 *     }>,
	 *     call_summaries: list<array{script_id: int, date: string, summary: string}>
	 * }
	 */
	public function buildInput(int $managerId, ?DateTime $now = null): array
	{
		$now ??= new DateTime();
		$from = (clone $now)->add('-' . self::WINDOW_DAYS . ' days');

		$calls = $this->fetchRatedCalls($managerId, $from, $now);
		$jobResults = $this->loadJobResults($calls);

		// total_calls is the headline count of every assessed call in the window; scripts_stats
		// is the per-script breakdown and by design skips calls with no script (scriptId <= 0),
		// so total_calls may exceed the sum of scripts_stats[].calls_count. This mismatch is
		// intentional, not a bug: the two numbers answer different questions.
		return [
			'agent_name' => (string)(Container::getInstance()->getUserBroker()->getName($managerId) ?? ''),
			'period' => $this->formatPeriod($from, $now),
			'total_calls' => count($calls),
			'scripts_stats' => $this->buildScriptsStats($calls, $jobResults),
			'call_summaries' => $this->buildCallSummaries($calls, $jobResults),
		];
	}

	/**
	 * Number of the manager's rated calls in the closed window [D-7d, D] - the data
	 * signature reused for dedup/caching of the summary AI job. Runs baseRatedCallsQuery()
	 * (the exact WHERE predicate shared with fetchRatedCalls()) with a bare COUNT(*) and no
	 * SETTINGS join - that LEFT join lives only in fetchRatedCalls() to resolve script titles.
	 * The count therefore never drifts from total_calls.
	 */
	public function countAssessedCalls(int $managerId, ?DateTime $now = null): int
	{
		$now ??= new DateTime();
		$from = (clone $now)->add('-' . self::WINDOW_DAYS . ' days');

		if ($managerId <= 0)
		{
			return 0;
		}

		$row = $this->baseRatedCallsQuery($managerId, $from, $now)
			->addSelect(new ExpressionField('CNT', 'COUNT(*)'))
			->fetch()
		;

		return (int)($row['CNT'] ?? 0);
	}

	/**
	 * The WHERE predicate shared by countAssessedCalls() and fetchRatedCalls(): the manager's
	 * rated calls within the closed window [$from, $to] (both bounds inclusive). Kept in one
	 * place so the COUNT and the fetch can never drift apart.
	 */
	private function baseRatedCallsQuery(int $managerId, DateTime $from, DateTime $to): Query
	{
		return AiQualityAssessmentTable::query()
			->where('RATED_USER_ID', $managerId)
			->where('USE_IN_RATING', true)
			->where('ACTIVITY_TYPE', AiQualityAssessmentTable::ACTIVITY_TYPE_CALL)
			->where('CREATED_AT', '>=', $from)
			->where('CREATED_AT', '<=', $to)
		;
	}

	/**
	 * Fetches the manager's rated calls within the closed window [$from, $to] (both bounds
	 * inclusive).
	 *
	 * No setLimit(): scripts_stats and total_calls aggregate over every rated call in the
	 * window, and total_calls (count of the fetched rows) must equal countAssessedCalls().
	 * A truncating limit would understate the statistics and break that invariant.
	 *
	 * @return list<array{id:int, jobId:int, scriptId:int, scriptName:string, createdAt:?DateTime}>
	 */
	private function fetchRatedCalls(int $managerId, DateTime $from, DateTime $to): array
	{
		if ($managerId <= 0)
		{
			return [];
		}

		$rows = $this->baseRatedCallsQuery($managerId, $from, $to)
			->registerRuntimeField(new Reference(
				'SETTINGS',
				CopilotCallAssessmentTable::class,
				['=this.ASSESSMENT_SETTING_ID' => 'ref.ID'],
				['join_type' => 'LEFT'],
			))
			->setSelect([
				'ID',
				'JOB_ID',
				'ASSESSMENT_SETTING_ID',
				'CREATED_AT',
				'SCRIPT_NAME' => 'SETTINGS.TITLE',
			])
			->setOrder(['CREATED_AT' => 'DESC', 'ID' => 'DESC'])
			->fetchAll()
		;

		$calls = [];
		foreach ($rows as $row)
		{
			$createdAt = $row['CREATED_AT'] ?? null;
			$calls[] = [
				'id' => (int)$row['ID'],
				'jobId' => (int)($row['JOB_ID'] ?? 0),
				'scriptId' => (int)($row['ASSESSMENT_SETTING_ID'] ?? 0),
				'scriptName' => (string)($row['SCRIPT_NAME'] ?? ''),
				'createdAt' => $createdAt instanceof DateTime ? $createdAt : null,
			];
		}

		return $calls;
	}

	/**
	 * @param list<array{id:int, jobId:int, scriptId:int, scriptName:string, createdAt:?DateTime}> $calls
	 * @param array<int, array{verdicts: array<string, bool>, recommendations: string}> $jobResults
	 * @return list<array{script_id:int, script_name:string, calls_count:int, criteria: list<array{criterion_id:int, criterion_name:string, score_pct:int, applicable_count:int}>}>
	 */
	private function buildScriptsStats(array $calls, array $jobResults): array
	{
		if (empty($calls))
		{
			return [];
		}

		$scriptOrder = [];
		$callsByScript = [];
		$scriptNames = [];
		foreach ($calls as $call)
		{
			$scriptId = $call['scriptId'];
			if ($scriptId <= 0)
			{
				// Calls with no script are intentionally excluded from the per-script breakdown;
				// they are still counted in total_calls (see buildInput). The two numbers differ
				// on purpose.
				continue;
			}

			if (!isset($callsByScript[$scriptId]))
			{
				$callsByScript[$scriptId] = [];
				$scriptOrder[] = $scriptId;
				$scriptNames[$scriptId] = $call['scriptName'];
			}
			$callsByScript[$scriptId][] = $call;
		}

		if (empty($scriptOrder))
		{
			return [];
		}

		$criteriaByScript = $this->criteriaLoader->loadForAssessments($scriptOrder);

		$scriptsStats = [];
		foreach ($scriptOrder as $scriptId)
		{
			$scriptCalls = $callsByScript[$scriptId];
			$criteria = [];
			foreach (($criteriaByScript[$scriptId] ?? []) as $criterion)
			{
				// Verdicts are matched to criteria BY TRIMMED NAME, not by id: the persisted
				// ScoreCallV2 result (b_crm_ai_queue.RESULT) stores only criterion_name for each
				// scored criterion (see parseResult()), never the criterion id. Consequence:
				// renaming a criterion after its calls were scored breaks the match - the stored
				// verdicts keep the old name while the criterion definition now carries the new
				// title, so the criterion is treated as not applicable to every call
				// (applicable_count = 0, score_pct = 0) until those calls are re-scored. This is a
				// known limitation of the data source, not a defect that can be fixed here.
				$name = trim((string)$criterion['title']);
				$metCount = 0;
				$applicableCount = 0;
				foreach ($scriptCalls as $call)
				{
					$verdict = $jobResults[$call['jobId']]['verdicts'][$name] ?? null;
					if ($verdict === null)
					{
						continue;
					}

					++$applicableCount;
					if ($verdict === true)
					{
						++$metCount;
					}
				}

				$criteria[] = [
					'criterion_id' => (int)$criterion['id'],
					'criterion_name' => (string)$criterion['title'],
					'score_pct' => $applicableCount > 0 ? (int)round($metCount / $applicableCount * 100) : 0,
					'applicable_count' => $applicableCount,
				];
			}

			$scriptsStats[] = [
				'script_id' => $scriptId,
				'script_name' => $scriptNames[$scriptId],
				'calls_count' => count($scriptCalls),
				'criteria' => $criteria,
			];
		}

		return $scriptsStats;
	}

	/**
	 * Loads and decodes each call's ScoreCallV2 job RESULT once, exposing both the
	 * per-criterion verdicts and the recommendations text keyed by jobId.
	 *
	 * @param list<array{id:int, jobId:int, scriptId:int, scriptName:string, createdAt:?DateTime}> $calls
	 * @return array<int, array{verdicts: array<string, bool>, recommendations: string}>
	 */
	private function loadJobResults(array $calls): array
	{
		$jobIds = [];
		foreach ($calls as $call)
		{
			if ($call['jobId'] > 0)
			{
				$jobIds[$call['jobId']] = true;
			}
		}
		$jobIds = array_keys($jobIds);
		if (empty($jobIds))
		{
			return [];
		}

		$rows = QueueTable::query()
			->setSelect(['ID', 'RESULT'])
			->whereIn('ID', $jobIds)
			->fetchAll()
		;

		$resultsByJob = [];
		foreach ($rows as $row)
		{
			$resultsByJob[(int)$row['ID']] = $this->parseResult((string)($row['RESULT'] ?? ''));
		}

		return $resultsByJob;
	}

	/**
	 * @return array{verdicts: array<string, bool>, recommendations: string}
	 */
	private function parseResult(string $result): array
	{
		$empty = ['verdicts' => [], 'recommendations' => ''];
		if ($result === '')
		{
			return $empty;
		}

		try
		{
			$decoded = Json::decode($result);
		}
		catch (ArgumentException)
		{
			return $empty;
		}

		if (!is_array($decoded))
		{
			return $empty;
		}

		$verdicts = [];
		$criteriaScores = $decoded['criteriaScores'] ?? null;
		if (is_array($criteriaScores))
		{
			foreach ($criteriaScores as $criterion)
			{
				if (!is_array($criterion))
				{
					continue;
				}

				// The stored result carries only criterion_name (no id), so verdicts are keyed by
				// the trimmed name and later matched to criteria definitions by name in
				// buildScriptsStats(). Renaming a criterion after scoring therefore orphans its
				// verdicts. Trim on both sides keeps the match stable against whitespace edits only.
				$name = trim((string)($criterion['criterion_name'] ?? ''));
				if ($name === '' || !array_key_exists('met', $criterion) || !is_bool($criterion['met']))
				{
					continue;
				}

				$verdicts[$name] = $criterion['met'];
			}
		}

		return [
			'verdicts' => $verdicts,
			'recommendations' => trim((string)($decoded['recommendations'] ?? '')),
		];
	}

	/**
	 * @param list<array{id:int, jobId:int, scriptId:int, scriptName:string, createdAt:?DateTime}> $calls
	 * @param array<int, array{verdicts: array<string, bool>, recommendations: string}> $jobResults
	 * @return list<array{script_id:int, date:string, summary:string}>
	 */
	private function buildCallSummaries(array $calls, array $jobResults): array
	{
		$summaries = [];
		$totalChars = 0;
		foreach ($calls as $call)
		{
			$recommendations = $jobResults[$call['jobId']]['recommendations'] ?? '';
			if ($recommendations === '')
			{
				continue;
			}

			$summary = $this->truncateSummary($recommendations);
			$summaryChars = mb_strlen($summary);

			// Stop before the cumulative budget is exceeded. Calls are ordered newest-first, so this
			// keeps the freshest summaries and drops older ones. The check is skipped for the first
			// summary (an empty $summaries), and a per-item-capped summary is always far smaller than
			// the block budget, so at least one summary is always emitted when data exists.
			if ($summaries !== [] && ($totalChars + $summaryChars) > self::CALL_SUMMARIES_BUDGET_CHARS)
			{
				break;
			}

			$summaries[] = [
				'script_id' => $call['scriptId'],
				'date' => $call['createdAt'] instanceof DateTime ? $call['createdAt']->format('d.m.Y') : '',
				'summary' => $summary,
			];
			$totalChars += $summaryChars;

			if (count($summaries) >= self::CALL_SUMMARIES_LIMIT)
			{
				break;
			}
		}

		return $summaries;
	}

	/**
	 * Caps a single recommendation to CALL_SUMMARY_MAX_CHARS, cutting on a character boundary
	 * (multibyte-safe) and appending a "…" marker so the truncation stays visible. The returned
	 * string never exceeds the limit: the one-char marker replaces the last kept character.
	 */
	private function truncateSummary(string $summary): string
	{
		if (mb_strlen($summary) <= self::CALL_SUMMARY_MAX_CHARS)
		{
			return $summary;
		}

		return mb_substr($summary, 0, self::CALL_SUMMARY_MAX_CHARS - 1) . '…';
	}

	private function formatPeriod(DateTime $from, DateTime $to): string
	{
		return $from->format('d.m.Y') . ' - ' . $to->format('d.m.Y');
	}
}
