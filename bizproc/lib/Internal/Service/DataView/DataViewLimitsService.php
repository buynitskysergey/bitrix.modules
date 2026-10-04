<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\DataView;

use Bitrix\Main\Config\Option;

final class DataViewLimitsService
{
	public const CHAIN_DEPTH = 3;

	private const DEFAULT_RESULT_ROWS_LIMIT = 1000;
	private const DEFAULT_AGGREGATE_INPUT_LIMIT = 10000;
	private const OPTION_RESULT_ROWS_LIMIT = 'dataview_result_rows_limit';
	private const OPTION_AGGREGATE_INPUT_LIMIT = 'dataview_aggregate_input_limit';

	public function getResultRowsLimit(): int
	{
		$limit = (int)Option::get('bizproc', self::OPTION_RESULT_ROWS_LIMIT, self::DEFAULT_RESULT_ROWS_LIMIT);

		return $limit > 0 ? $limit : self::DEFAULT_RESULT_ROWS_LIMIT;
	}

	public function getAggregateInputLimit(): int
	{
		$limit = (int)Option::get('bizproc', self::OPTION_AGGREGATE_INPUT_LIMIT, self::DEFAULT_AGGREGATE_INPUT_LIMIT);

		return $limit > 0 ? $limit : self::DEFAULT_AGGREGATE_INPUT_LIMIT;
	}
}
