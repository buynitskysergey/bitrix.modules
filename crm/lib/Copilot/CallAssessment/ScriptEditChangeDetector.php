<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\CallAssessment;

use Bitrix\Crm\Copilot\CallAssessment\Dto\ScriptStructureDto;

final class ScriptEditChangeDetector
{
	public static function hasStructureChanged(
		ScriptStructureDto $prev,
		ScriptStructureDto $next,
	): bool
	{
		if ($next->callType !== 0 && $next->callType !== $prev->callType)
		{
			return true;
		}

		if (self::isIntSetChanged($prev->clientTypeIds, $next->clientTypeIds))
		{
			return true;
		}

		return self::isCriteriaSetChanged($prev->criteria, $next->criteria);
	}

	/**
	 * @param int[] $a
	 * @param int[] $b
	 */
	public static function isIntSetChanged(array $a, array $b): bool
	{
		return self::normalizeIntSet($a) !== self::normalizeIntSet($b);
	}

	/**
	 * @param int[] $list
	 * @return int[]
	 */
	private static function normalizeIntSet(array $list): array
	{
		$ints = array_values(array_unique($list));
		sort($ints);

		return $ints;
	}

	/**
	 * @param array<int, array{title?: string, description?: string}> $prev
	 * @param array<int, array{title?: string, description?: string}> $next
	 */
	public static function isCriteriaSetChanged(array $prev, array $next): bool
	{
		return self::normalizeCriteriaSet($prev) !== self::normalizeCriteriaSet($next);
	}

	/**
	 * @param array<int, array{title?: string, description?: string}> $rows
	 * @return string[]
	 */
	private static function normalizeCriteriaSet(array $rows): array
	{
		$out = [];
		foreach ($rows as $row)
		{
			$out[] = trim($row['title'] ?? '') . "\0" . trim($row['description'] ?? '');
		}
		sort($out);

		return $out;
	}
}
