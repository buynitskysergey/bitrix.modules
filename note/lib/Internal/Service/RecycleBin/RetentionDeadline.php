<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\RecycleBin;

use Bitrix\Main\Type\DateTime;
use Bitrix\Note\Internal\Configuration;

/**
 * When the cleanup agent takes a trashed document away. Every warning that names the date - the
 * screen inside a trashed node - reads it from here, so it cannot drift from the retention the
 * agent itself goes by.
 */
final class RetentionDeadline
{
	/**
	 * @return string|null null when there is nothing deleted, or when retention is switched off and
	 *         nothing will be taken away at all
	 */
	public static function forTrashedAt(?DateTime $trashedAt): ?string
	{
		$deadline = self::timestampFor($trashedAt);

		return $deadline === null ? null : DateTime::createFromTimestamp($deadline)->format('c');
	}

	/**
	 * Whether the cleanup agent may already take this away. The boundary is the same comparison the
	 * agent selects by - TRASHED_AT strictly older than now minus the retention - so the return out
	 * of the bin and the purge can never be allowed at the same moment, and never both forbidden.
	 */
	public static function hasPassed(?DateTime $trashedAt): bool
	{
		$deadline = self::timestampFor($trashedAt);

		return $deadline !== null && $deadline < time();
	}

	private static function timestampFor(?DateTime $trashedAt): ?int
	{
		if ($trashedAt === null)
		{
			return null;
		}

		$ttl = Configuration::getRecycleBinTtl();
		if ($ttl < 0)
		{
			return null;
		}

		return $trashedAt->getTimestamp() + $ttl * 86400;
	}
}
