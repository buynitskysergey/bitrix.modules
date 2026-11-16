<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Agent\RecycleBin;

use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;
use Bitrix\Note\Internal\Configuration;
use Bitrix\Note\Internal\Repository\CollectionRepository;
use Bitrix\Note\Internal\Repository\RecycleBinRepository;
use Bitrix\Note\Internal\Service\RecycleBin\HardDeleteCollectionService;
use Bitrix\Note\Internal\Service\RecycleBin\HardDeleteService;

final class RecycleBinCleanupAgent
{
	private const CHUNK_SIZE = 100;
	private const WATCHDOG_SECONDS = 10.0;
	private const RESCHEDULE_EXPRESSION = 'Bitrix\Note\Infrastructure\Agent\RecycleBin\RecycleBinCleanupAgent::run();';

	/**
	 * Cleans up recycle-bin entries older than the configured TTL, documents first and then the
	 * knowledge bases themselves. A base whose documents the first pass has already taken away is
	 * handled the same as any other.
	 *
	 * The whole tick lives inside one budget: bases are big now that deleting a base sends all of its
	 * documents to the bin, so the budget is checked between chunks inside a base as well as between
	 * bases. A base cut short keeps what is left for the next tick.
	 *
	 * @param float $budgetSeconds How long one tick may work; a test squeezes it, the agent does not.
	 * @return string Empty string to self-unregister; otherwise the reschedule expression.
	 */
	public static function run(float $budgetSeconds = self::WATCHDOG_SECONDS): string
	{
		$ttl = Configuration::getRecycleBinTtl();
		if ($ttl < 0)
		{
			return '';
		}

		$cutoff = DateTime::createFromTimestamp(time() - $ttl * 86400);
		$repository = new RecycleBinRepository();
		$hardDelete = new HardDeleteService();

		$deadline = microtime(true) + $budgetSeconds;
		$totalDeleted = 0;

		while (true)
		{
			$records = $repository->listExpiredAt($cutoff, self::CHUNK_SIZE);
			if (empty($records))
			{
				break;
			}

			$documentIds = array_map(
				static fn($record): int => (int)$record->getDocumentId(),
				$records,
			);
			$preparedLinkChange = $hardDelete->prepareDelete($documentIds);

			$connection = Application::getConnection();
			$connection->startTransaction();
			try
			{
				$cleanupPayload = $hardDelete->deleteByDocumentIds($documentIds, $preparedLinkChange);
				$connection->commitTransaction();
			}
			catch (\Throwable $e)
			{
				$connection->rollbackTransaction();
				self::logError('RecycleBinCleanupAgent: ' . $e->getMessage());

				return self::RESCHEDULE_EXPRESSION;
			}

			$hardDelete->runPostCommitCleanup(
				$cleanupPayload['fileIds'],
				$cleanupPayload['documentIds'],
			);

			$totalDeleted += count($documentIds);

			if (microtime(true) > $deadline)
			{
				break;
			}
		}

		$totalCollections = self::purgeExpiredCollections($cutoff, $deadline);

		if ($totalDeleted > 0 || $totalCollections > 0)
		{
			\CEventLog::Add([
				'SEVERITY' => 'INFO',
				'AUDIT_TYPE_ID' => 'NOTE_RECYCLE_BIN_HARD_DELETE',
				'MODULE_ID' => 'note',
				'DESCRIPTION' => 'Deleted ' . $totalDeleted . ' expired recycle-bin entries and '
					. $totalCollections . ' knowledge bases (ttl=' . $ttl . ' days)',
			]);
		}

		return self::RESCHEDULE_EXPRESSION;
	}

	/**
	 * Second pass: the bases whose stay is over, each one taken away whole. A base someone is
	 * restoring or purging right now is locked - it is left for the next run, not waited for.
	 *
	 * The first base is always attempted, even when the documents pass has already spent the budget:
	 * otherwise a bin full of expired documents would starve the bases tick after tick.
	 *
	 * @return int bases removed whole
	 */
	private static function purgeExpiredCollections(DateTime $cutoff, float $deadline): int
	{
		$repository = new CollectionRepository();
		$service = new HardDeleteCollectionService();
		$purged = 0;

		while (true)
		{
			$collectionIds = $repository->listTrashedBefore($cutoff, self::CHUNK_SIZE);
			if (empty($collectionIds))
			{
				break;
			}

			$purgedInChunk = 0;
			foreach ($collectionIds as $collectionId)
			{
				try
				{
					$result = $service->purge($collectionId, $deadline);
				}
				catch (\Throwable $e)
				{
					self::logError('RecycleBinCleanupAgent: ' . $e->getMessage());

					return $purged;
				}

				// A base the deadline cut short is half-empty and still in the bin; it does not count as
				// removed, and the next tick carries on with the documents it has left.
				if ($result->isSuccess() && ($result->getData()['completed'] ?? true))
				{
					$purged++;
					$purgedInChunk++;
				}

				if (microtime(true) > $deadline)
				{
					return $purged;
				}
			}

			// Every base of the chunk is busy: the same chunk would come back unchanged.
			if ($purgedInChunk === 0)
			{
				break;
			}
		}

		return $purged;
	}

	private static function logError(string $message): void
	{
		\CEventLog::Add([
			'SEVERITY' => \CEventLog::SEVERITY_ERROR,
			'AUDIT_TYPE_ID' => 'NOTE_RECYCLE_BIN_CLEANUP_ERROR',
			'MODULE_ID' => 'note',
			'DESCRIPTION' => $message,
		]);
	}
}
