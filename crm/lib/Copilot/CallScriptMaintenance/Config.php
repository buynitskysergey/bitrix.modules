<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\CallScriptMaintenance;

use Bitrix\Main\Config\Option;

final class Config
{
	private const MODULE_ID = 'crm';

	private const CONFIDENCE_THRESHOLD = 'call_script_grouping_confidence_threshold';
	private const GROUPING_MIN_CALLS = 'call_script_grouping_min_calls';
	private const GROUPING_MIN_GROUP_SIZE = 'call_script_grouping_min_group_size';
	private const GROUPING_MAX_CALLS_PER_RUN = 'call_script_grouping_max_calls_per_run';
	private const ENRICHMENT_MIN_CALLS = 'call_script_enrichment_min_calls';
	private const ENRICHMENT_BATCH_SIZE = 'call_script_enrichment_batch_size';
	private const CYCLE_PERIOD = 'call_script_maintenance_period_seconds';
	private const STEP_INTERVAL = 'call_script_maintenance_step_interval_seconds';
	private const AI_POLL_INTERVAL = 'call_script_maintenance_ai_poll_interval_seconds';
	private const AI_WAIT_TIMEOUT = 'call_script_maintenance_ai_wait_timeout_seconds';
	private const CONTEXT_TTL = 'call_script_maintenance_context_ttl_seconds';

	public static function getConfidenceThreshold(): int
	{
		return self::getValue(self::CONFIDENCE_THRESHOLD, 50);
	}

	public static function getGroupingMinCalls(): int
	{
		return self::getValue(self::GROUPING_MIN_CALLS, 5);
	}

	public static function getGroupingMinGroupSize(): int
	{
		return self::getValue(self::GROUPING_MIN_GROUP_SIZE, 5);
	}

	public static function getGroupingMaxCallsPerRun(): int
	{
		return self::getValue(self::GROUPING_MAX_CALLS_PER_RUN, 200);
	}

	public static function getEnrichmentMinCalls(): int
	{
		return self::getValue(self::ENRICHMENT_MIN_CALLS, 5);
	}

	public static function getEnrichmentBatchSize(): int
	{
		return self::getValue(self::ENRICHMENT_BATCH_SIZE, 15);
	}

	public static function getCyclePeriodSeconds(): int
	{
		return self::getValue(self::CYCLE_PERIOD, 604800);
	}

	public static function getStepIntervalSeconds(): int
	{
		return self::getValue(self::STEP_INTERVAL, 60);
	}

	public static function getAiPollIntervalSeconds(): int
	{
		return self::getValue(self::AI_POLL_INTERVAL, 300);
	}

	public static function getAiWaitTimeoutSeconds(): int
	{
		return self::getValue(self::AI_WAIT_TIMEOUT, 3600);
	}

	public static function getContextTtlSeconds(): int
	{
		return self::getValue(self::CONTEXT_TTL, 604800);
	}

	private static function getValue(string $key, int $default): int
	{
		$value = Option::get(self::MODULE_ID, $key, (string)$default);

		return (int)$value;
	}
}
