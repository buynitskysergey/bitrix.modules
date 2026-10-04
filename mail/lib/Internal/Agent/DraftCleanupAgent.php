<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Agent;

use Bitrix\Mail\Internal\Repository\DraftRepository;
use Bitrix\Mail\Internal\Service\Draft\AttachmentStorage;
use Bitrix\Mail\Internals\DraftTable;
use Bitrix\Main\Application;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\DateTime;

final class DraftCleanupAgent
{
	private const BATCH_SIZE = 100;
	private const TIME_LIMIT_SECONDS = 20;

	public static function getName(): string
	{
		return 'Bitrix\\Mail\\Internal\\Agent\\DraftCleanupAgent::run();';
	}

	public static function run(): string
	{
		$repository = new DraftRepository();
		self::cleanupDrafts(
			[
				self::createBatchLoader(
					DraftTable::STATUS_COMPLETED,
					'DATE_COMPLETED',
					new DateTime(),
				),
				self::createBatchLoader(
					DraftTable::STATUS_ACTIVE,
					'DATE_EXPIRE',
					new DateTime(),
				),
			],
			static fn(int $draftId) => $repository->deleteAggregate($draftId),
			static fn(\Throwable $exception) => Application::getInstance()
				->getExceptionHandler()
				->writeToLog($exception),
			microtime(true) + self::TIME_LIMIT_SECONDS,
		);

		try
		{
			(new AttachmentStorage())->cleanupOrphans();
		}
		catch (\Throwable)
		{
			// The next scheduled run retries orphan cleanup.
		}

		return self::getName();
	}

	private static function cleanupDrafts(
		array $loaders,
		callable $deleteDraft,
		callable $logException,
		float $deadline,
	): void
	{
		$cursors = array_fill(0, count($loaders), null);
		$finished = array_fill(0, count($loaders), false);
		do
		{
			$hasPendingQueue = false;
			foreach ($loaders as $loaderIndex => $loadBatch)
			{
				if ($finished[$loaderIndex])
				{
					continue;
				}

				$rows = $loadBatch($cursors[$loaderIndex]);
				foreach ($rows as $row)
				{
					$draftId = (int)$row['ID'];
					$cursors[$loaderIndex] = [
						'date' => $row['CLEANUP_DATE'],
						'id' => $draftId,
					];
					try
					{
						$deleteDraft($draftId);
					}
					catch (\Throwable $exception)
					{
						try
						{
							$logException($exception);
						}
						catch (\Throwable)
						{
							// Cleanup must continue even if error logging is unavailable.
						}
					}

					if (microtime(true) >= $deadline)
					{
						return;
					}
				}

				$finished[$loaderIndex] = count($rows) < self::BATCH_SIZE;
				$hasPendingQueue = $hasPendingQueue || !$finished[$loaderIndex];
			}
		}
		while ($hasPendingQueue);
	}

	private static function createBatchLoader(
		string $status,
		string $dateField,
		DateTime $cutoff,
	): callable
	{
		return static function(?array $cursor) use ($status, $dateField, $cutoff): array
		{
			$query = DraftTable::query()
				->setSelect(['ID', 'CLEANUP_DATE' => $dateField])
				->where('STATUS', $status)
				->where($dateField, '<=', $cutoff)
				->setOrder([$dateField => 'ASC', 'ID' => 'ASC'])
				->setLimit(self::BATCH_SIZE)
			;
			if ($cursor !== null)
			{
				$query->where(
					Query::filter()
						->logic('or')
						->where($dateField, '>', $cursor['date'])
						->where(
							Query::filter()
								->where($dateField, $cursor['date'])
								->where('ID', '>', $cursor['id']),
						),
				);
			}

			return $query->fetchAll();
		};
	}
}
