<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Agent\RecycleBin;

use Bitrix\Main\Config\Option;
use Bitrix\Note\Internal\Repository\RecycleBinRepository;

/**
 * One-time backfill of the two columns the section list of the recycle bin is read from - the base a
 * row belongs to and whether it is a deletion root. Rows written before the columns existed carry
 * the defaults, and a row at the defaults is drawn in no section, so a portal that already has a bin
 * needs this pass before the list shows anything.
 *
 * Registered by the updater only: a fresh install writes both columns with every row. Forward-only
 * keyset over b_note_recycle_bin.ID, with a watchdog per tick, and it removes itself once the scan
 * reaches the end, leaving a completion marker in the cursor option so that the next module update,
 * which registers it again, does not rerun the pass. Runs often and in small portions on purpose -
 * the part of the bin it has not reached yet is missing from the list until it does.
 */
final class RecycleBinRootBackfillAgent
{
	private const MODULE_ID = 'note';
	private const CHUNK_SIZE = 500;
	private const WATCHDOG_SECONDS = 10.0;
	private const OPTION_CURSOR = 'recycle_bin_root_backfill_cursor';
	private const CURSOR_DONE = 'done';
	private const RESCHEDULE_EXPRESSION = 'Bitrix\Note\Infrastructure\Agent\RecycleBin\RecycleBinRootBackfillAgent::run();';

	/**
	 * @return string Empty string to self-unregister; otherwise the reschedule expression.
	 */
	public static function run(): string
	{
		if (self::isCompleted())
		{
			return '';
		}

		$repository = new RecycleBinRepository();
		$cursor = self::loadCursor();
		$startTime = microtime(true);

		while (true)
		{
			try
			{
				$lastId = $repository->backfillFlagsPage($cursor, self::CHUNK_SIZE);
			}
			catch (\Throwable $e)
			{
				// The cursor keeps what the previous portions reached; the next tick retries from there.
				self::logError('portion after ' . $cursor . ': ' . $e->getMessage());
				self::saveCursor($cursor);

				return self::RESCHEDULE_EXPRESSION;
			}

			if ($lastId === null)
			{
				self::markCompleted();

				return '';
			}

			$cursor = $lastId;

			if ((microtime(true) - $startTime) > self::WATCHDOG_SECONDS)
			{
				break;
			}
		}

		self::saveCursor($cursor);

		return self::RESCHEDULE_EXPRESSION;
	}

	private static function loadCursor(): int
	{
		return max(0, (int)Option::get(self::MODULE_ID, self::OPTION_CURSOR, '0'));
	}

	/**
	 * The pass is done once and for all: the updater runs on every module update and registers the
	 * agent again, and a second pass over rows the current code already writes would fight it.
	 */
	private static function isCompleted(): bool
	{
		return Option::get(self::MODULE_ID, self::OPTION_CURSOR, '') === self::CURSOR_DONE;
	}

	private static function saveCursor(int $cursor): void
	{
		Option::set(self::MODULE_ID, self::OPTION_CURSOR, (string)max(0, $cursor));
	}

	private static function markCompleted(): void
	{
		Option::set(self::MODULE_ID, self::OPTION_CURSOR, self::CURSOR_DONE);
	}

	private static function logError(string $message): void
	{
		\CEventLog::Add([
			'SEVERITY' => \CEventLog::SEVERITY_ERROR,
			'AUDIT_TYPE_ID' => 'NOTE_RECYCLE_BIN_ROOT_BACKFILL_ERROR',
			'MODULE_ID' => self::MODULE_ID,
			'DESCRIPTION' => 'RecycleBinRootBackfillAgent: ' . $message,
		]);
	}
}
