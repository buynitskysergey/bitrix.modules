<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Agent\RecycleBin;

use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;
use Bitrix\Note\Internal\Configuration;
use Bitrix\Note\Internal\Repository\RecycleBinRepository;
use Bitrix\Note\Internal\Service\RecycleBin\HardDeleteService;

final class RecycleBinCleanupAgent
{
	private const CHUNK_SIZE = 100;
	private const WATCHDOG_SECONDS = 10.0;
	private const RESCHEDULE_EXPRESSION = 'Bitrix\Note\Infrastructure\Agent\RecycleBin\RecycleBinCleanupAgent::run();';

	/**
	 * Cleans up recycle-bin entries older than the configured TTL.
	 *
	 * @return string Empty string to self-unregister; otherwise the reschedule expression.
	 */
	public static function run(): string
	{
		$ttl = Configuration::getRecycleBinTtl();
		if ($ttl < 0)
		{
			return '';
		}

		$cutoff = DateTime::createFromTimestamp(time() - $ttl * 86400);
		$repository = new RecycleBinRepository();
		$hardDelete = new HardDeleteService();

		$startTime = microtime(true);
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

			if ((microtime(true) - $startTime) > self::WATCHDOG_SECONDS)
			{
				break;
			}
		}

		if ($totalDeleted > 0)
		{
			\CEventLog::Add([
				'SEVERITY' => 'INFO',
				'AUDIT_TYPE_ID' => 'NOTE_RECYCLE_BIN_HARD_DELETE',
				'MODULE_ID' => 'note',
				'DESCRIPTION' => 'Deleted ' . $totalDeleted . ' expired recycle-bin entries (ttl=' . $ttl . ' days)',
			]);
		}

		return self::RESCHEDULE_EXPRESSION;
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
