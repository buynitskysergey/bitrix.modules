<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Agent\MainDocument;

use Bitrix\Main\Config\Option;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Service\Document\MainDocumentService;
use Bitrix\Note\Internal\Service\User\SystemUser;

/**
 * One-time backfill: creates a main document for every pre-existing collection
 * that still lacks one. Registered by the module updater on existing installations;
 * fresh installs need it not (main documents are created eagerly at collection creation).
 *
 * Idempotent: MainDocumentService::ensureMainDocument() is a no-op when a main document
 * already exists. Progress is a forward-only keyset scan over b_note_collection.ID: the
 * highest examined collection id is persisted between ticks (Option) and each chunk advances
 * the cursor past every collection it touched — including permanently failing ones. That
 * guarantees the agent always moves forward, never rescans already-processed or "stuck"
 * collections (no quasi-quadratic re-scan, no per-tick log flood on the same failures), and
 * self-unregisters (returns '') once the scan reaches the end. A defensive give-up stops the
 * agent after MAX_FRUITLESS_TICKS consecutive ticks that create nothing, so a large region of
 * failing collections cannot keep it rescheduling forever. Chunked with a watchdog like
 * RecycleBinCleanupAgent.
 */
final class MainDocumentBackfillAgent
{
	private const MODULE_ID = 'note';
	private const CHUNK_SIZE = 100;
	private const WATCHDOG_SECONDS = 10.0;
	private const MAX_FRUITLESS_TICKS = 3;
	private const OPTION_CURSOR = 'main_document_backfill_cursor';
	private const OPTION_FRUITLESS_TICKS = 'main_document_backfill_fruitless_ticks';
	private const RESCHEDULE_EXPRESSION = 'Bitrix\Note\Infrastructure\Agent\MainDocument\MainDocumentBackfillAgent::run();';

	/**
	 * @return string Empty string to self-unregister; otherwise the reschedule expression.
	 */
	public static function run(): string
	{
		$repository = new DocumentRepository();
		$mainDocumentService = new MainDocumentService($repository);

		$cursor = self::loadCursor();
		$startTime = microtime(true);
		$totalCreated = 0;
		$finished = false;

		while (true)
		{
			$collectionIds = $repository->findCollectionIdsWithoutMainDocument(self::CHUNK_SIZE, $cursor);
			if (empty($collectionIds))
			{
				// The forward scan reached the end — every collection has a main document.
				$finished = true;

				break;
			}

			foreach ($collectionIds as $collectionId)
			{
				try
				{
					$mainId = $mainDocumentService->ensureMainDocument($collectionId, SystemUser::ID);
				}
				catch (\Throwable $e)
				{
					self::logError('collection ' . $collectionId . ': ' . $e->getMessage());

					continue;
				}

				if ($mainId === null)
				{
					self::logError('collection ' . $collectionId . ': ensureMainDocument returned null');

					continue;
				}

				$totalCreated++;
			}

			// Advance the keyset cursor past this chunk regardless of per-collection outcome:
			// a permanently failing collection must not be rescanned on the next tick (it would
			// block progress and flood the log), and backfilled ones never reappear anyway.
			$cursor = max($collectionIds);

			if ((microtime(true) - $startTime) > self::WATCHDOG_SECONDS)
			{
				break;
			}
		}

		if ($finished)
		{
			self::logProgress($totalCreated, true);
			self::clearState();

			return '';
		}

		self::saveCursor($cursor);

		if ($totalCreated > 0)
		{
			self::logProgress($totalCreated, false);
			self::resetFruitlessTicks();

			return self::RESCHEDULE_EXPRESSION;
		}

		// No main document created this tick: only failing collections remained. Bound the
		// retries so a large failing region cannot keep the agent rescheduling forever.
		if (self::registerFruitlessTick() >= self::MAX_FRUITLESS_TICKS)
		{
			self::logError(
				'no progress after ' . self::MAX_FRUITLESS_TICKS
				. ' consecutive ticks — stopping the backfill',
			);
			self::clearState();

			return '';
		}

		return self::RESCHEDULE_EXPRESSION;
	}

	private static function loadCursor(): int
	{
		return max(0, (int)Option::get(self::MODULE_ID, self::OPTION_CURSOR, '0'));
	}

	private static function saveCursor(int $cursor): void
	{
		Option::set(self::MODULE_ID, self::OPTION_CURSOR, (string)max(0, $cursor));
	}

	private static function registerFruitlessTick(): int
	{
		$ticks = (int)Option::get(self::MODULE_ID, self::OPTION_FRUITLESS_TICKS, '0') + 1;
		Option::set(self::MODULE_ID, self::OPTION_FRUITLESS_TICKS, (string)$ticks);

		return $ticks;
	}

	private static function resetFruitlessTicks(): void
	{
		if ((int)Option::get(self::MODULE_ID, self::OPTION_FRUITLESS_TICKS, '0') !== 0)
		{
			Option::set(self::MODULE_ID, self::OPTION_FRUITLESS_TICKS, '0');
		}
	}

	private static function clearState(): void
	{
		Option::delete(self::MODULE_ID, ['name' => self::OPTION_CURSOR]);
		Option::delete(self::MODULE_ID, ['name' => self::OPTION_FRUITLESS_TICKS]);
	}

	private static function logProgress(int $totalCreated, bool $finished): void
	{
		if ($totalCreated <= 0)
		{
			return;
		}

		\CEventLog::Add([
			'SEVERITY' => 'INFO',
			'AUDIT_TYPE_ID' => 'NOTE_MAIN_DOCUMENT_BACKFILL',
			'MODULE_ID' => 'note',
			'DESCRIPTION' => 'Created ' . $totalCreated . ' main document(s)'
				. ($finished ? ' (backfill complete)' : ' (chunk processed)'),
		]);
	}

	private static function logError(string $message): void
	{
		\CEventLog::Add([
			'SEVERITY' => \CEventLog::SEVERITY_ERROR,
			'AUDIT_TYPE_ID' => 'NOTE_MAIN_DOCUMENT_BACKFILL_ERROR',
			'MODULE_ID' => 'note',
			'DESCRIPTION' => 'MainDocumentBackfillAgent: ' . $message,
		]);
	}
}
