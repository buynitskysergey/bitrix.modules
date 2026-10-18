<?php

namespace Bitrix\StaffTrack\Service\CheckIn;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Type\DateTime;
use Bitrix\StaffTrack\Integration\Im\MessageService;
use Bitrix\StaffTrack\Internal\Repository\CheckInByDayRepository;
use Bitrix\StaffTrack\Internal\Repository\CheckInRepository;
use Bitrix\StaffTrack\Public\Services\MapDataCache;

class CheckInGeoCleanupService
{
	private const MODULE_ID = 'stafftrack';
	private const RETENTION_MONTHS = 3;
	private const BATCH_SIZE = 500;
	private const SNAPSHOT_BATCH_SIZE = 200;
	private const SNAPSHOT_SUBDIR = 'stafftrack/static_map';
	private const WINDOW_OVERLAP_DAYS = 7;

	private const OPTION_CLEANED_UNTIL_RAW = 'clear_geo_cleanup_cleaned_until_raw';
	private const OPTION_OFFSET_RAW = 'clear_geo_cleanup_offset_raw';
	private const OPTION_CLEANED_UNTIL_BY_DAY = 'clear_geo_cleanup_cleaned_until_by_day';
	private const OPTION_OFFSET_BY_DAY = 'clear_geo_cleanup_offset_by_day';
	private const OPTION_CURSOR_SNAPSHOT = 'clear_geo_cleanup_cursor_snapshot';

	private CheckInRepository $checkInRepository;
	private CheckInByDayRepository $checkInByDayRepository;
	private MessageService $messageService;

	private bool $mapDataDirty = false;

	public function __construct(
		CheckInRepository $checkInRepository,
		CheckInByDayRepository $checkInByDayRepository,
		MessageService $messageService,
	)
	{
		$this->checkInRepository = $checkInRepository;
		$this->checkInByDayRepository = $checkInByDayRepository;
		$this->messageService = $messageService;
	}

	/**
	 * @return void
	 */
	public function resetProgress(): void
	{
		$this->deleteOption(self::OPTION_CLEANED_UNTIL_RAW);
		$this->setCursor(self::OPTION_OFFSET_RAW, 0);
		$this->deleteOption(self::OPTION_CLEANED_UNTIL_BY_DAY);
		$this->setCursor(self::OPTION_OFFSET_BY_DAY, 0);
		$this->setCursor(self::OPTION_CURSOR_SNAPSHOT, 0);
	}

	/**
	 * @param int $timeLimitSeconds
	 * @return bool
	 */
	public function run(int $timeLimitSeconds): bool
	{
		$startTime = time();
		$threshold = $this->getThreshold();

		$hasMore = true;
		$hasMoreRaw = $this->clearRawCheckIns($threshold, $startTime, $timeLimitSeconds);

		if (!$this->isTimeExceeded($startTime, $timeLimitSeconds))
		{
			$hasMoreAggregated = $this->clearAggregated($threshold, $startTime, $timeLimitSeconds);

			if (!$this->isTimeExceeded($startTime, $timeLimitSeconds))
			{
				$hasMoreSnapshots = $this->clearSnapshots($threshold, $startTime, $timeLimitSeconds);
				$hasMore = $hasMoreRaw || $hasMoreAggregated || $hasMoreSnapshots;
			}
		}

		$this->flushMapDataCache();

		return $hasMore;
	}

	/**
	 * @return void
	 */
	private function flushMapDataCache(): void
	{
		if ($this->mapDataDirty)
		{
			MapDataCache::invalidateAll();
			$this->mapDataDirty = false;
		}
	}

	/**
	 * @param DateTime $threshold
	 * @param int $startTime
	 * @param int $timeLimitSeconds
	 * @return bool
	 */
	public function clearRawCheckIns(DateTime $threshold, int $startTime = 0, int $timeLimitSeconds = 0): bool
	{
		$cleanedUntil = $this->getCleanedUntil(self::OPTION_CLEANED_UNTIL_RAW, $threshold);
		$windowStart = $this->getWindowStart($cleanedUntil);
		$offset = $this->getCursor(self::OPTION_OFFSET_RAW);

		do
		{
			$rows = $this->checkInRepository->getStaleRawCheckIns($windowStart, $threshold, $offset, self::BATCH_SIZE);
			if (empty($rows))
			{
				$this->finishWindow(self::OPTION_CLEANED_UNTIL_RAW, self::OPTION_OFFSET_RAW, $threshold);

				return false;
			}

			$messageIds = [];
			$dirtyIds = [];
			$maxId = $offset;

			foreach ($rows as $row)
			{
				$maxId = max($maxId, (int)$row['ID']);

				$hasGeo = ($row['LATITUDE'] !== null || $row['LONGITUDE'] !== null || $row['ADDRESS'] !== null);
				$rowMessages = $row['MESSAGE_IDS'];
				$hasMessages = is_array($rowMessages) && !empty($rowMessages);

				if (!$hasGeo && !$hasMessages)
				{
					continue;
				}

				$dirtyIds[] = (int)$row['ID'];
				$this->mapDataDirty = true;

				if ($hasMessages)
				{
					foreach ($rowMessages as $msgId)
					{
						$messageId = (int)$msgId;
						if ($messageId > 0)
						{
							$messageIds[] = $messageId;
						}
					}
				}
			}

			if (!empty($messageIds))
			{
				$this->messageService->deleteMessages($messageIds);
			}

			if (!empty($dirtyIds))
			{
				$this->checkInRepository->clearGeoFieldsByIds($dirtyIds);
			}

			$offset = $maxId;
			$this->setCursor(self::OPTION_OFFSET_RAW, $offset);

			if (count($rows) < self::BATCH_SIZE)
			{
				$this->finishWindow(self::OPTION_CLEANED_UNTIL_RAW, self::OPTION_OFFSET_RAW, $threshold);

				return false;
			}
		}
		while (!$this->isTimeExceeded($startTime, $timeLimitSeconds));

		return true;
	}

	/**
	 * @param DateTime $threshold
	 * @param int $startTime
	 * @param int $timeLimitSeconds
	 * @return bool
	 */
	public function clearAggregated(DateTime $threshold, int $startTime = 0, int $timeLimitSeconds = 0): bool
	{
		$cleanedUntil = $this->getCleanedUntil(self::OPTION_CLEANED_UNTIL_BY_DAY, $threshold);
		$windowStart = $this->getWindowStart($cleanedUntil);
		$offset = $this->getCursor(self::OPTION_OFFSET_BY_DAY);

		do
		{
			$rows = $this->checkInByDayRepository->getStaleByDayRows($windowStart, $threshold, $offset, self::BATCH_SIZE);
			if (empty($rows))
			{
				$this->finishWindow(self::OPTION_CLEANED_UNTIL_BY_DAY, self::OPTION_OFFSET_BY_DAY, $threshold);

				return false;
			}

			$maxId = $offset;
			foreach ($rows as $row)
			{
				$maxId = max($maxId, (int)$row['ID']);
				if ($this->processAggregatedRow($row))
				{
					$this->mapDataDirty = true;
				}
			}

			$offset = $maxId;
			$this->setCursor(self::OPTION_OFFSET_BY_DAY, $offset);

			if (count($rows) < self::BATCH_SIZE)
			{
				$this->finishWindow(self::OPTION_CLEANED_UNTIL_BY_DAY, self::OPTION_OFFSET_BY_DAY, $threshold);

				return false;
			}
		}
		while (!$this->isTimeExceeded($startTime, $timeLimitSeconds));

		return true;
	}

	/**
	 * @param DateTime $threshold
	 * @param int $startTime
	 * @param int $timeLimitSeconds
	 * @return bool
	 */
	public function clearSnapshots(DateTime $threshold, int $startTime = 0, int $timeLimitSeconds = 0): bool
	{
		// b_file: TIMESTAMP_X and ID are monotonic (CFile stamps current time, files are not edited),
		// so a plain ID high-water-mark is sufficient. No daily reset, no terminal "done" marker:
		// newly aged files always have ID > cursor and are picked up on subsequent runs.
		$cursor = $this->getCursor(self::OPTION_CURSOR_SNAPSHOT);

		$connection = Application::getConnection();
		$sqlHelper = $connection->getSqlHelper();

		$moduleId = $sqlHelper->forSql(self::MODULE_ID);
		$subDirPrefix = $sqlHelper->forSql(self::SNAPSHOT_SUBDIR);
		$thresholdSql = $sqlHelper->convertToDbDateTime($threshold);
		$batchSize = self::SNAPSHOT_BATCH_SIZE;

		do
		{
			$rows = $connection->query(
				"SELECT ID FROM b_file
				WHERE MODULE_ID = '{$moduleId}'
				AND SUBDIR LIKE '{$subDirPrefix}%'
				AND TIMESTAMP_X <= {$thresholdSql}
				AND ID > {$cursor}
				ORDER BY ID ASC
				LIMIT {$batchSize}",
			)->fetchAll();

			if (empty($rows))
			{
				return false;
			}

			$maxId = $cursor;
			foreach ($rows as $row)
			{
				$maxId = max($maxId, (int)$row['ID']);

				\CFile::Delete((int)$row['ID']);
				$this->mapDataDirty = true;
			}

			$cursor = $maxId;
			$this->setCursor(self::OPTION_CURSOR_SNAPSHOT, $cursor);

			if (count($rows) < self::SNAPSHOT_BATCH_SIZE)
			{
				return false;
			}
		}
		while (!$this->isTimeExceeded($startTime, $timeLimitSeconds));

		return true;
	}

	/**
	 * @param array $row
	 * @return bool
	 */
	private function processAggregatedRow(array $row): bool
	{
		$geoDataRaw = (string)($row['GEO_DATA'] ?? '');
		if ($geoDataRaw === '')
		{
			return false;
		}

		$points = json_decode($geoDataRaw, true);
		if (!is_array($points))
		{
			return false;
		}

		$messageIds = [];
		$hasGeoToClear = false;

		foreach ($points as $point)
		{
			if (
				($point['LATITUDE'] ?? null) !== null
				|| ($point['LONGITUDE'] ?? null) !== null
				|| ($point['ADDRESS'] ?? null) !== null
			)
			{
				$hasGeoToClear = true;
			}

			$pointMessageIds = $point['MESSAGE_IDS'] ?? [];
			if (is_array($pointMessageIds))
			{
				foreach ($pointMessageIds as $msgId)
				{
					$messageId = (int)$msgId;
					if ($messageId > 0)
					{
						$messageIds[] = $messageId;
						$hasGeoToClear = true;
					}
				}
			}
		}

		if (!$hasGeoToClear)
		{
			return false;
		}

		if (!empty($messageIds))
		{
			$this->messageService->deleteMessages($messageIds);
		}

		$cleanedPoints = [];
		foreach ($points as $point)
		{
			$point['LATITUDE'] = null;
			$point['LONGITUDE'] = null;
			$point['ADDRESS'] = null;
			$point['MESSAGE_IDS'] = [];

			$cleanedPoints[] = $point;
		}

		$this->checkInByDayRepository->updateGeoData(
			(int)$row['ID'],
			json_encode($cleanedPoints),
		);

		return true;
	}

	private function getThreshold(): DateTime
	{
		return (new DateTime())->add('-' . self::RETENTION_MONTHS . ' months');
	}

	/**
	 * @param string $key
	 * @param DateTime $threshold
	 * @return DateTime
	 */
	private function getCleanedUntil(string $key, DateTime $threshold): DateTime
	{
		$stored = Option::get(self::MODULE_ID, $key, '');
		if ($stored === '')
		{
			return clone $threshold;
		}

		return DateTime::createFromTimestamp((int)$stored);
	}

	/**
	 * @param DateTime $cleanedUntil
	 * @return DateTime
	 */
	private function getWindowStart(DateTime $cleanedUntil): DateTime
	{
		return (clone $cleanedUntil)->add('-' . self::WINDOW_OVERLAP_DAYS . ' days');
	}

	/**
	 * @param string $cleanedUntilKey
	 * @param string $offsetKey
	 * @param DateTime $threshold
	 * @return void
	 */
	private function finishWindow(string $cleanedUntilKey, string $offsetKey, DateTime $threshold): void
	{
		Option::set(self::MODULE_ID, $cleanedUntilKey, (string)$threshold->getTimestamp());
		$this->setCursor($offsetKey, 0);
	}

	private function deleteOption(string $key): void
	{
		Option::delete(self::MODULE_ID, ['name' => $key]);
	}

	private function isTimeExceeded(int $startTime, int $timeLimitSeconds): bool
	{
		return (time() - $startTime) >= $timeLimitSeconds;
	}

	private function getCursor(string $key): int
	{
		return (int)Option::get(self::MODULE_ID, $key, '0');
	}

	private function setCursor(string $key, int $value): void
	{
		Option::set(self::MODULE_ID, $key, (string)$value);
	}
}