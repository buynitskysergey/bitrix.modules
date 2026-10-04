<?php

declare(strict_types=1);

namespace Bitrix\Crm\RepeatSale\Transcription;

use Bitrix\Crm\Activity\Provider\Call;
use Bitrix\Crm\ActivityTable;
use Bitrix\Crm\Integration\VoxImplantManager;
use Bitrix\Crm\Item;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Config\Option;
use CCrmActivity;
use CCrmOwnerType;

/**
 * Collects the call activities of a client that are eligible for transcription.
 *
 * Scope: base_deal + deals_list (other non-recurring deals of the client). Only VoxImplant calls
 * with a recording that are not transcribed yet (state != Ready) are returned, capped by the
 * per-client limit. Priority is normative: base_deal calls first, then deals_list by freshness.
 *
 * When a per-client token budget is configured, the selection additionally stops early once the
 * projected transcript volume (ready transcripts already in scope + duration-based estimates of the
 * calls picked for launch) reaches the budget - so calls whose text would not fit are not launched.
 *
 * The collector only reads and orders; it never launches transcription and never writes state.
 */
class InScopeCallCollector
{
	public const CALLS_LIMIT_OPTION = 'repeat_sale_transcription_calls_limit';
	public const DEFAULT_CALLS_LIMIT = 5;

	// Upper bound on how many freshest call activities per scan are read and hydrated. Only the
	// count/budget limit (a few calls) is ever selected, but a call-center client can have hundreds
	// of calls per deal; without this cap the scan would load and fully hydrate all of them on every
	// pass (and on every retry). Freshest-first order means the cap keeps the relevant recent calls.
	public const SCAN_LIMIT_OPTION = 'repeat_sale_transcription_scan_limit';
	public const DEFAULT_SCAN_LIMIT = 20;

	// conservative token estimate of a call of unknown duration: a full-length capped transcript,
	// i.e. the per-item char cap (20000, TextLengthConfig) expressed in tokens (~20000 / 2.5)
	public const DEFAULT_PER_CALL_TOKEN_CAP = 8000;

	private TranscriptStateResolver $stateResolver;
	private TranscriptionBudget $budgetConfig;

	public function __construct(
		?TranscriptStateResolver $stateResolver = null,
		?TranscriptionBudget $budgetConfig = null,
	)
	{
		$this->stateResolver = $stateResolver ?? new TranscriptStateResolver();
		$this->budgetConfig = $budgetConfig ?? new TranscriptionBudget();
	}

	/**
	 * @param array<int, array{entityTypeId?: int, entityId?: int}> $clientIdentifiers
	 *
	 * @return InScopeCall[]
	 */
	public function collect(array $clientIdentifiers, int $baseDealId, ?int $limit = null, ?int $budgetTokens = null): array
	{
		$limit ??= $this->getCallsLimit();
		if ($limit <= 0 || $baseDealId <= 0)
		{
			return [];
		}

		$budgetTokens ??= $this->getContextBudgetTokens();

		return $budgetTokens > 0
			? $this->collectWithinBudget($clientIdentifiers, $baseDealId, $limit, $budgetTokens)
			: $this->collectByCountLimit($clientIdentifiers, $baseDealId, $limit)
		;
	}

	/**
	 * Priority selection bounded by the count limit (ALG-03). Used when the budget is disabled.
	 *
	 * The limit counts ready (already-transcribed) and newly-selected calls together: an
	 * already-transcribed call in scope occupies a slot, so a client with enough transcripts is not
	 * re-transcribed. Only untranscribed calls are returned for launch; a Ready call fills a slot
	 * without a launch. The base deal is exhausted first (own scan window), then deals_list by
	 * freshness, until the shared limit is reached.
	 *
	 * @param array<int, array{entityTypeId?: int, entityId?: int}> $clientIdentifiers
	 *
	 * @return InScopeCall[]
	 */
	private function collectByCountLimit(array $clientIdentifiers, int $baseDealId, int $limit): array
	{
		$selected = [];
		$consumed = 0;

		// priority 1: base_deal (own scan window so its calls are not pushed out by deals_list)
		$this->selectByCount($this->loadCallStates([$baseDealId])[$baseDealId] ?? [], $baseDealId, $limit, $selected, $consumed);
		if ($consumed >= $limit)
		{
			return $selected;
		}

		// priority 2: deals_list, freshest deals first
		$otherDealIds = $this->fetchClientDealIdsByFreshness($clientIdentifiers, $baseDealId, $limit);
		if (empty($otherDealIds))
		{
			return $selected;
		}

		$statesByDeal = $this->loadCallStates($otherDealIds);
		foreach ($otherDealIds as $dealId)
		{
			$this->selectByCount($statesByDeal[$dealId] ?? [], $dealId, $limit, $selected, $consumed);
			if ($consumed >= $limit)
			{
				return $selected;
			}
		}

		return $selected;
	}

	/**
	 * Walks one deal's calls in freshness order, counting ready and newly-selected calls toward the
	 * shared limit. A terminal-empty (Skip) call carries no work and is ignored; a Ready call occupies
	 * a slot without a launch; an untranscribed (None/Pending) call occupies a slot and is handed to
	 * the gate.
	 *
	 * @param array<int, array{activityId: int, state: TranscriptState}> $calls
	 * @param InScopeCall[] $selected
	 */
	private function selectByCount(array $calls, int $dealId, int $limit, array &$selected, int &$consumed): void
	{
		foreach ($calls as $call)
		{
			if ($consumed >= $limit)
			{
				return;
			}

			if ($call['state'] === TranscriptState::Skip)
			{
				continue;
			}

			if ($call['state'] !== TranscriptState::Ready)
			{
				$selected[] = new InScopeCall($call['activityId'], $dealId, $call['responsibleId'] ?? 0);
			}

			$consumed++;
		}
	}

	/**
	 * Priority selection (ALG-03) with a per-client token budget (ALG-05): the volume already
	 * taken by ready transcripts across the whole scope plus the duration-based estimate of each
	 * launched call is projected; the selection stops once the budget is reached. The count limit
	 * still bounds the result from above.
	 *
	 * @param array<int, array{entityTypeId?: int, entityId?: int}> $clientIdentifiers
	 *
	 * @return InScopeCall[]
	 */
	private function collectWithinBudget(array $clientIdentifiers, int $baseDealId, int $limit, int $budgetTokens): array
	{
		// priority-ordered scope: base first, then deals_list by freshness (bounded by the count limit)
		$orderedDealIds = [$baseDealId];
		foreach ($this->fetchClientDealIdsByFreshness($clientIdentifiers, $baseDealId, $limit) as $dealId)
		{
			$orderedDealIds[] = $dealId;
		}

		// Scan the base deal on its own scan window so its (typically older) calls are not pushed out
		// of the freshest-first scan cap by a call-center client's fresher deals_list calls - the same
		// separate-scan guarantee the count-limit path gives base_deal priority (ALG-03).
		$scope = $this->loadScopeCalls([$baseDealId]);
		$otherDealIds = array_slice($orderedDealIds, 1);
		if (!empty($otherDealIds))
		{
			$scope += $this->loadScopeCalls($otherDealIds);
		}

		// prefetch durations for all launch candidates in one telephony query - the per-call estimate
		// below would otherwise trigger a separate query per candidate on every pass and retry
		$candidateCallIds = [];
		foreach ($scope as $entries)
		{
			foreach ($entries as $entry)
			{
				if (!$entry['state']->isTerminal() && $entry['callId'] !== null)
				{
					$candidateCallIds[] = $entry['callId'];
				}
			}
		}
		$this->warmCallDurations($candidateCallIds);

		// Single priority-ordered pass (base deal first, then deals_list by freshness) so the budget is
		// consumed in the same order the downstream TranscriptBudgetTrimmer applies it (ALG-04/ALG-05).
		// A ready transcript already in scope occupies the budget (each capped at the per-item volume,
		// at most the per-deal freshest ones, so an old or unusually long transcript cannot over-count);
		// an untranscribed call adds its duration-based estimate. Accounting ready and new calls in the
		// same priority order means a low-priority deal's ready transcripts can no longer exhaust the
		// budget before the base deal's own untranscribed calls are selected.
		$selected = [];
		$projected = 0;
		foreach ($orderedDealIds as $dealId)
		{
			$readyCount = 0;
			foreach ($scope[$dealId] ?? [] as $entry)
			{
				if ($entry['state'] === TranscriptState::Ready)
				{
					if ($readyCount < $limit)
					{
						$projected += min($entry['readyTokens'], self::DEFAULT_PER_CALL_TOKEN_CAP);
						$readyCount++;
					}

					continue;
				}

				if ($entry['state']->isTerminal())
				{
					continue;
				}

				if (count($selected) >= $limit || $projected >= $budgetTokens)
				{
					return $selected;
				}

				$selected[] = new InScopeCall($entry['activityId'], $dealId, $entry['responsibleId'] ?? 0);
				$projected += $this->estimateTokens($this->getCallDurationSeconds($entry['callId']));
			}
		}

		return $selected;
	}

	protected function getCallsLimit(): int
	{
		$limit = (int)Option::get('crm', self::CALLS_LIMIT_OPTION, self::DEFAULT_CALLS_LIMIT);

		return $limit > 0 ? $limit : self::DEFAULT_CALLS_LIMIT;
	}

	protected function getScanLimit(): int
	{
		$limit = (int)Option::get('crm', self::SCAN_LIMIT_OPTION, self::DEFAULT_SCAN_LIMIT);

		return $limit > 0 ? $limit : self::DEFAULT_SCAN_LIMIT;
	}

	protected function getContextBudgetTokens(): int
	{
		return $this->budgetConfig->getContextBudgetTokens();
	}

	/**
	 * Client deals_list ids ordered fresh -> old, excluding the base deal.
	 *
	 * @param array<int, array{entityTypeId?: int, entityId?: int}> $clientIdentifiers
	 *
	 * @return int[]
	 */
	protected function fetchClientDealIdsByFreshness(array $clientIdentifiers, int $baseDealId, int $limit): array
	{
		$filter = $this->buildClientDealsFilter($clientIdentifiers, $baseDealId);
		if (empty($filter))
		{
			return [];
		}

		$factory = Container::getInstance()->getFactory(CCrmOwnerType::Deal);
		if ($factory === null)
		{
			return [];
		}

		$items = $factory->getItems([
			'select' => [Item::FIELD_NAME_ID],
			'filter' => $filter,
			'order' => [Item::FIELD_NAME_CREATED_TIME => 'DESC'],
			'limit' => $limit,
		]);

		$dealIds = [];
		foreach ($items as $item)
		{
			$dealIds[] = $item->getId();
		}

		return $dealIds;
	}

	/**
	 * @param array<int, array{entityTypeId?: int, entityId?: int}> $clientIdentifiers
	 *
	 * @return array<string, mixed>
	 */
	protected function buildClientDealsFilter(array $clientIdentifiers, int $baseDealId): array
	{
		$filter = [];
		foreach ($clientIdentifiers as $clientIdentifier)
		{
			$clientEntityTypeId = (int)($clientIdentifier['entityTypeId'] ?? 0);
			$clientEntityId = (int)($clientIdentifier['entityId'] ?? 0);
			if ($clientEntityId <= 0)
			{
				continue;
			}

			if ($clientEntityTypeId === CCrmOwnerType::Contact)
			{
				$filter['@' . Item::FIELD_NAME_CONTACT_BINDINGS . '.CONTACT_ID'][] = $clientEntityId;
			}
			elseif ($clientEntityTypeId === CCrmOwnerType::Company)
			{
				$filter['@' . Item::FIELD_NAME_COMPANY_ID][] = $clientEntityId;
			}
		}

		if (empty($filter))
		{
			return [];
		}

		$filter['=' . Item::FIELD_NAME_IS_RECURRING] = 'N';
		if ($baseDealId > 0)
		{
			$filter['!=' . Item::FIELD_NAME_ID] = $baseDealId;
		}

		return $filter;
	}

	/**
	 * Loads suitable (VoxImplant call with a recording) calls for the given deals with their
	 * transcript state, ready ones included, with a single activity query + a single batched activity
	 * load (N+1 protection). Freshness order (CREATED desc) is preserved within each deal. Lighter than
	 * loadScopeCalls(): no transcript-volume measurement, since the count-limit path does not need it.
	 *
	 * @param int[] $dealIds
	 *
	 * @return array<int, array<int, array{activityId: int, responsibleId: int, state: TranscriptState}>> dealId => entries
	 */
	protected function loadCallStates(array $dealIds): array
	{
		$suitable = $this->scanSuitableCalls($dealIds);
		if (empty($suitable))
		{
			return [];
		}

		$this->stateResolver->warmCache($this->collectActivityIds($suitable));

		$result = [];
		foreach ($suitable as $dealId => $entries)
		{
			foreach ($entries as $entry)
			{
				$result[$dealId][] = [
					'activityId' => $entry['activityId'],
					'responsibleId' => $entry['responsibleId'] ?? 0,
					'state' => $this->stateResolver->resolveState($entry['activityId']),
				];
			}
		}

		return $result;
	}

	/**
	 * Same batched scan as loadSuitableUntranscribedCalls(), but exposes the full picture needed by
	 * the budget projection: the transcript state, the ready-text length and the call id (for the
	 * duration estimate) of every suitable call, ready ones included.
	 *
	 * @param int[] $dealIds
	 *
	 * @return array<int, array<int, array{activityId: int, callId: ?string, responsibleId: int, state: TranscriptState, readyTokens: int}>>
	 */
	protected function loadScopeCalls(array $dealIds): array
	{
		$suitable = $this->scanSuitableCalls($dealIds);
		if (empty($suitable))
		{
			return [];
		}

		$this->stateResolver->warmCache($this->collectActivityIds($suitable));

		$result = [];
		foreach ($suitable as $dealId => $entries)
		{
			foreach ($entries as $entry)
			{
				$activityId = $entry['activityId'];
				$state = $this->stateResolver->resolveState($activityId);
				$result[$dealId][] = [
					'activityId' => $activityId,
					'callId' => $entry['callId'],
					'responsibleId' => $entry['responsibleId'] ?? 0,
					'state' => $state,
					'readyTokens' => $state === TranscriptState::Ready
						? $this->stateResolver->getReadyTranscriptTokens($activityId)
						: 0
					,
				];
			}
		}

		return $result;
	}

	/**
	 * Filters to VoxImplant calls with a recording and extracts the call id, one bounded query per deal.
	 * Freshness order (CREATED desc) is preserved within each deal.
	 *
	 * The scan cap is applied per deal, not across the whole set: a shared cap over whereIn(dealIds)
	 * would let one deal with many fresh calls consume the entire scan window and push the other deals
	 * out of scope, breaking the deals_list-by-freshness priority (ALG-03). deals_list is bounded by
	 * the count limit (a few deals), so this is a small bounded number of queries, not an N+1 scan.
	 *
	 * @param int[] $dealIds
	 *
	 * @return array<int, array<int, array{activityId: int, callId: ?string, responsibleId: int}>> dealId => entries
	 */
	private function scanSuitableCalls(array $dealIds): array
	{
		$dealIds = array_values(array_unique(array_filter(
			array_map('intval', $dealIds),
			static fn (int $id): bool => $id > 0,
		)));
		if (empty($dealIds))
		{
			return [];
		}

		$result = [];
		foreach ($dealIds as $dealId)
		{
			$entries = $this->scanDealSuitableCalls($dealId);
			if (!empty($entries))
			{
				$result[$dealId] = $entries;
			}
		}

		return $result;
	}

	/**
	 * Single bounded, freshest-first scan of one deal: never reads/hydrates more than the scan cap,
	 * even for a client with a very long call history (ALG-03 selection still applies the count/budget
	 * limit after). The cap is intentionally shallow and coordinated with the downstream consumer: the
	 * data collector (CallRecordingStrategy via ActivityDataCollector::DEFAULT_LIMIT) only reads the
	 * freshest few call activities per deal, so a suitable call hidden below the scan cap is older than
	 * that window anyway - scanning deeper would only pay to transcribe calls the tip never reads.
	 *
	 * Only the fields the suitability filter (VoxImplant + recording), the call-id extraction and the
	 * launch (responsible user) actually need are selected. A broker bunch-load would instead pull the
	 * full activity per row (DESCRIPTION, PROVIDER_PARAMS, PROVIDER_DATA and other TEXT/LONGTEXT fields)
	 * on every pass and retry.
	 *
	 * @return array<int, array{activityId: int, callId: ?string, responsibleId: int}>
	 */
	private function scanDealSuitableCalls(int $dealId): array
	{
		$rows = ActivityTable::query()
			->setSelect(['ID', 'OWNER_ID', 'PROVIDER_ID', 'ORIGIN_ID', 'STORAGE_ELEMENT_IDS', 'RESPONSIBLE_ID'])
			->where('OWNER_TYPE_ID', CCrmOwnerType::Deal)
			->where('OWNER_ID', $dealId)
			->where('PROVIDER_ID', Call::getId())
			->setOrder(['CREATED' => 'DESC'])
			->setLimit($this->getScanLimit())
			->fetchAll()
		;

		$entries = [];
		foreach ($rows as $row)
		{
			$activityId = (int)$row['ID'];
			if ($activityId <= 0 || !$this->isSuitableForTranscription($row))
			{
				continue;
			}

			$entries[] = [
				'activityId' => $activityId,
				'callId' => $this->extractCallId($row),
				'responsibleId' => (int)($row['RESPONSIBLE_ID'] ?? 0),
			];
		}

		return $entries;
	}

	/**
	 * @param array<int, array<int, array{activityId: int, ...}>> $callsByDeal
	 *
	 * @return int[]
	 */
	private function collectActivityIds(array $callsByDeal): array
	{
		$activityIds = [];
		foreach ($callsByDeal as $entries)
		{
			foreach ($entries as $entry)
			{
				$activityIds[] = $entry['activityId'];
			}
		}

		return $activityIds;
	}

	/**
	 * @param array<string, mixed> $activityFields
	 */
	private function extractCallId(array $activityFields): ?string
	{
		$originId = $activityFields['ORIGIN_ID'] ?? null;
		if (!is_string($originId) || !VoxImplantManager::isVoxImplantOriginId($originId))
		{
			return null;
		}

		return VoxImplantManager::extractCallIdFromOriginId($originId);
	}

	/**
	 * @param string[] $callIds
	 */
	protected function warmCallDurations(array $callIds): void
	{
		VoxImplantManager::warmCallDurations($callIds);
	}

	protected function getCallDurationSeconds(?string $callId): ?int
	{
		if ($callId === null || $callId === '')
		{
			return null;
		}

		return VoxImplantManager::getCallDuration($callId);
	}

	/**
	 * Token estimate of a not-yet-transcribed call for the budget projection. A known positive
	 * duration is converted with the configured tokens-per-second coefficient; an unknown duration is
	 * treated conservatively as a "large" call (the per-call token cap) so the projection is not
	 * understated.
	 */
	protected function estimateTokens(?int $durationSeconds): int
	{
		if ($durationSeconds === null || $durationSeconds <= 0)
		{
			return self::DEFAULT_PER_CALL_TOKEN_CAP;
		}

		return $durationSeconds * $this->budgetConfig->getTokensPerSecond();
	}

	/**
	 * @param array<string, mixed> $activityFields
	 */
	protected function isSuitableForTranscription(array $activityFields): bool
	{
		if (!VoxImplantManager::isActivityBelongsToVoximplant($activityFields))
		{
			return false;
		}

		$storageElementIds = CCrmActivity::extractStorageElementIds($activityFields) ?? [];

		return !empty($storageElementIds);
	}
}
