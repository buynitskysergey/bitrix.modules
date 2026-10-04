<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Agent\Link;

use Bitrix\Main\Config\Option;
use Bitrix\Note\Internal\Model\DocumentTable;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Service\Link\DocumentLinkIndexService;

/**
 * [P4.T2] One-time pass that fills b_note_document_link for content written before the feature
 * existed. The live writer only reacts to a save, so without this pass a document nobody touches
 * again would never appear in anyone's backlinks.
 *
 * Forward-only keyset scan over b_note_document.ID, the cursor kept in Option between ticks and
 * advanced past every chunk whatever happened inside it, so the pass is strictly finite and
 * self-unregisters (returns '') when the scan runs out of rows. Each document goes through the
 * ordinary {@see DocumentLinkIndexService} rebuild, which rewrites the whole target set of a
 * source: meeting the same document twice — a watchdog break inside a chunk, a second pass, an
 * ordinary save racing the agent — writes the same set again and nothing else, so reentrancy costs
 * nothing extra here.
 *
 * The rollout order this belongs to is: install with the UI flag off, let the pass finish, then
 * turn the flag on. A durable "done" marker ({@see OPTION_DONE}) survives {@see clearState()} and is
 * checked on entry, so a pass registered again after it finished costs one empty tick instead of a
 * second full scan. The check has to live here rather than in the updater that registers the agent:
 * an updater runs with the module unloaded, where note classes are not autoloadable and the new
 * files may not have arrived yet, so registration is necessarily blind.
 *
 * Documents in the legacy json format are counted, not rebuilt: their MARKDOWN holds an encoded
 * TipTap tree the extractor must never see (same reasoning as in the index service). The count goes
 * into the completion record, which is what makes the ADR's "practically none are left" assumption
 * checkable on a live portal instead of assumed.
 */
final class DocumentLinkBackfillAgent
{
	private const MODULE_ID = 'note';
	private const CHUNK_SIZE = 100;
	private const WATCHDOG_SECONDS = 10.0;
	private const MAX_FRUITLESS_TICKS = 3;
	private const OPTION_CURSOR = 'document_link_backfill_cursor';
	private const OPTION_FRUITLESS_TICKS = 'document_link_backfill_fruitless_ticks';
	private const OPTION_REBUILT = 'document_link_backfill_rebuilt';
	private const OPTION_SKIPPED = 'document_link_backfill_skipped';
	private const OPTION_FAILED = 'document_link_backfill_failed';
	// [C4] Permanent completion marker — the ONE option {@see clearState()} must not erase. {@see run()}
	// checks it on entry so a re-registered pass leaves without re-scanning the table.
	public const OPTION_DONE = 'document_link_backfill_done';
	private const RESCHEDULE_EXPRESSION = 'Bitrix\Note\Infrastructure\Agent\Link\DocumentLinkBackfillAgent::run();';

	/**
	 * @return string Empty string to self-unregister; otherwise the reschedule expression.
	 */
	public static function run(): string
	{
		// [C4] Whoever registered this row could not know the pass is over — see the class comment.
		if (self::isDone())
		{
			return '';
		}

		$repository = new DocumentRepository();
		$indexService = new DocumentLinkIndexService();
		$repairScheduler = new DocumentLinkRepairScheduler();

		$cursor = self::loadCursor();
		$startTime = microtime(true);
		$rebuilt = 0;
		$skipped = 0;
		$failed = 0;
		$finished = false;

		while (true)
		{
			$rows = $repository->listIdsWithContentFormatAfter($cursor, self::CHUNK_SIZE);
			if (empty($rows))
			{
				$finished = true;

				break;
			}

			$mdIds = [];
			foreach ($rows as $row)
			{
				// Advanced before the work, not after it: a skipped or failing document must move
				// the scan on all the same, or the next chunk starts where this one did.
				$cursor = max($cursor, $row['ID']);

				if ($row['CONTENT_FORMAT'] === DocumentTable::CONTENT_FORMAT_JSON)
				{
					$skipped++;

					continue;
				}

				$mdIds[] = $row['ID'];
			}

			if (!empty($mdIds))
			{
				// [C2-perf] Whole chunk in two batched reads instead of two SELECTs per document.
				$result = $indexService->rebuildBatch($mdIds);
				$rebuilt += $result['rebuilt'];

				foreach ($result['failedIds'] as $failedId)
				{
					$failed++;
					// [C2-corr] A failed document is NOT left to a save that may never come: the cursor
					// has already moved past it and clearState() will wipe the pass, so hand it to the
					// durable repair agent instead of dropping it silently.
					$repairScheduler->schedule((int)$failedId);
					self::logError('document ' . $failedId . ': backfill rebuild failed');
				}
			}

			if ((microtime(true) - $startTime) > self::WATCHDOG_SECONDS)
			{
				break;
			}
		}

		$totals = self::addToTotals($rebuilt, $skipped, $failed);

		if ($finished)
		{
			self::logCompletion($totals, 'complete');
			self::markDone();
			self::clearState();

			return '';
		}

		self::saveCursor($cursor);

		if ($rebuilt > 0)
		{
			self::resetFruitlessTicks();

			return self::RESCHEDULE_EXPRESSION;
		}

		if ($failed === 0)
		{
			// A chunk of nothing but legacy documents is not a fruitless tick: the scan moved on.
			return self::RESCHEDULE_EXPRESSION;
		}

		// Every document this tick actually tried to reindex failed. That is a broken portal, not a
		// broken document, so the retries are bounded instead of running the table out.
		if (self::registerFruitlessTick() >= self::MAX_FRUITLESS_TICKS)
		{
			// [C4] Deliberately NOT markDone(): the scan stopped short of the end of the table, so the
			// tail is still unindexed. clearState() drops the run state (cursor included), which lets the
			// next update check re-register the agent for a fresh full pass that reaches the stuck tail
			// again — setting the done marker here would gate that re-registration off forever.
			self::logCompletion($totals, 'stopped after ' . self::MAX_FRUITLESS_TICKS . ' ticks without progress');
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

	/**
	 * The counters are carried across ticks so the closing record covers the whole pass, not the
	 * last chunk of it.
	 *
	 * @return array{rebuilt: int, skipped: int, failed: int}
	 */
	private static function addToTotals(int $rebuilt, int $skipped, int $failed): array
	{
		return [
			'rebuilt' => self::addToTotal(self::OPTION_REBUILT, $rebuilt),
			'skipped' => self::addToTotal(self::OPTION_SKIPPED, $skipped),
			'failed' => self::addToTotal(self::OPTION_FAILED, $failed),
		];
	}

	/**
	 * @return int The running total after this tick's contribution.
	 */
	private static function addToTotal(string $name, int $delta): int
	{
		$total = max(0, (int)Option::get(self::MODULE_ID, $name, '0')) + $delta;
		if ($delta > 0)
		{
			Option::set(self::MODULE_ID, $name, (string)$total);
		}

		return $total;
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

	/**
	 * [C4] Whether the one-time pass has already run to completion on this portal.
	 */
	public static function isDone(): bool
	{
		return Option::get(self::MODULE_ID, self::OPTION_DONE, 'N') === 'Y';
	}

	private static function markDone(): void
	{
		Option::set(self::MODULE_ID, self::OPTION_DONE, 'Y');
	}

	private static function clearState(): void
	{
		foreach (
			[
				self::OPTION_CURSOR,
				self::OPTION_FRUITLESS_TICKS,
				self::OPTION_REBUILT,
				self::OPTION_SKIPPED,
				self::OPTION_FAILED,
			] as $name
		)
		{
			Option::delete(self::MODULE_ID, ['name' => $name]);
		}
	}

	/**
	 * @param array{rebuilt: int, skipped: int, failed: int} $totals
	 */
	private static function logCompletion(array $totals, string $outcome): void
	{
		\CEventLog::Add([
			'SEVERITY' => $totals['failed'] > 0 ? \CEventLog::SEVERITY_ERROR : \CEventLog::SEVERITY_INFO,
			'AUDIT_TYPE_ID' => 'NOTE_DOCUMENT_LINK_BACKFILL',
			'MODULE_ID' => 'note',
			'DESCRIPTION' => 'DocumentLinkBackfillAgent ' . $outcome
				. ': rebuilt ' . $totals['rebuilt']
				. ', skipped by json format ' . $totals['skipped']
				. ', failed ' . $totals['failed'],
		]);
	}

	private static function logError(string $message): void
	{
		\CEventLog::Add([
			'SEVERITY' => \CEventLog::SEVERITY_ERROR,
			'AUDIT_TYPE_ID' => 'NOTE_DOCUMENT_LINK_BACKFILL_ERROR',
			'MODULE_ID' => 'note',
			'DESCRIPTION' => 'DocumentLinkBackfillAgent: ' . $message,
		]);
	}
}
