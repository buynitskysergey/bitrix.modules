<?php

namespace Bitrix\StaffTrack\Public\Command\CheckIn;

use Bitrix\Main\Loader;
use Bitrix\Main\Application;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Error;
use Bitrix\Main\Text\Emoji;
use Bitrix\StaffTrack\Internal\Entity\CheckInType;
use Bitrix\StaffTrack\Internals\Exception\InvalidDtoException;
use Bitrix\StaffTrack\Public\Command\CheckInDto;
use Bitrix\StaffTrack\Public\Provider\CheckInNormalizer;
use Bitrix\StaffTrack\Public\Services\DailyStatsUpdater;

/**
 * Batch write-path for auto check-ins: a client submits accumulated geolocation
 * points in a single call. The whole batch is persisted in one transaction with a
 * single daily-stats recompute and one aggregated PULL notification.
 *
 * This command is batch-only. It intentionally skips the IM branch (dialogIds/snapshotUrl)
 * and the workday auto-start that the single-point {@see Add} command performs.
 */
class AddBatch extends AbstractCommand
{
	private array $batchDelta = [];

	/**
	 * Upper bound on the number of points accepted in a single batch. Enforced server-side
	 * so any facade consumer ({@see \Bitrix\StaffTrack\Public\Services\CheckInService::addBatch()})
	 * cannot open an unbounded transaction, not just the mobile transport.
	 */
	public const MAX_BATCH_SIZE = 100;

	/**
	 * Required by {@see AbstractCommand}, but AddBatch is a batch-only command.
	 * Single-point execution must go through {@see Add}; {@see self::executeBatch()} is the entry point.
	 */
	public function execute(CheckInDto $checkInDto): Result
	{
		return (new Result())->addError(
			new Error('AddBatch supports batch execution only; use executeBatch()'),
		);
	}

	/**
	 * @param CheckInDto[] $checkInDtos Pre-assembled DTOs (userId/timezone forced by the caller).
	 */
	public function executeBatch(array $checkInDtos): Result
	{
		$result = new Result();

		if (!Loader::includeModule('location'))
		{
			return $result->addError(new Error('Module "location" not installed'));
		}

		if (empty($checkInDtos))
		{
			return $result->setData(['checkIns' => []]);
		}

		if (count($checkInDtos) > self::MAX_BATCH_SIZE)
		{
			return $result->addError(
				new Error('Check-in batch size exceeds the limit of ' . self::MAX_BATCH_SIZE),
			);
		}

		$dtos = [];
		foreach ($checkInDtos as $checkInDto)
		{
			$dto = clone $checkInDto;
			$dto->setId(0);
			// Batch is auto-check-in only; client attachments are not carried into this path
			// (not persisted and not pushed) - the single Add path owns file normalization/transfer.
			$dto->setFileIds([]);

			try
			{
				$dto->validateAdd();
			}
			catch (InvalidDtoException $e)
			{
				return $result->addError(new Error($e->getMessage()));
			}

			if ($dto->entityType !== CheckInType::AUTOMATIC->value)
			{
				return $result->addError(
					new Error('Batch check-in accepts automatic check-ins only'),
				);
			}

			$dateError = self::fitDateCreateToWindow($dto);
			if ($dateError !== null)
			{
				return $result->addError($dateError);
			}

			if ($dto->description !== null)
			{
				$dto->description = Emoji::encode($dto->description);
			}

			$dtos[] = $dto;
		}

		// Batch contract: one call = one user/timezone. saveBatch() persists each point under its
		// own userId, but the single daily-stats recompute and the aggregated PULL are keyed off
		// $dtos[0]; a mixed batch would desync stats and misroute the notification. Enforce the
		// precondition before the transaction so the write stays all-or-nothing and fails fast.
		$userId = $dtos[0]->userId;
		$userTimezone = $dtos[0]->userTimezone;
		foreach ($dtos as $dto)
		{
			if ($dto->userId !== $userId || $dto->userTimezone !== $userTimezone)
			{
				return $result->addError(
					new Error('Batch check-in requires a single userId and timezone for the whole batch'),
				);
			}
		}

		$saveResult = $this->saveBatch($dtos);
		if (!$saveResult->isSuccess())
		{
			return $result->addErrors($saveResult->getErrors());
		}

		$this->sendBatchPushToDepartment($dtos, $this->batchDelta);

		return $result->setData([
			'checkIns' => $this->prepareResults($dtos),
		]);
	}

	/**
	 * @param CheckInDto[] $dtos
	 */
	private function saveBatch(array $dtos): Result
	{
		$result = new Result();

		// Resolving the rollout flags can walk the department tree, so it is done before the transaction.
		$isDeltaNeeded = $this->isBatchDeltaNeededByRecipients($dtos[0]->userId);
		$statsUpdater = new DailyStatsUpdater();

		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$points = [];
			foreach ($dtos as $dto)
			{
				if ($dto->dateCreate === null)
				{
					$dto->setDateCreate(new DateTime());
				}

				$checkIn = $this->mapper->createEntityFromDto($dto);
				$saveResult = $checkIn->save();
				if (!$saveResult->isSuccess())
				{
					$connection->rollbackTransaction();

					return $result->addErrors($saveResult->getErrors());
				}

				$dto->id = (int)$saveResult->getId();

				$points[] = [
					'id' => $dto->id,
					'entityType' => $dto->entityType,
					'description' => (string)($dto->description ?? ''),
					'timestamp' => $dto->dateCreate?->getTimestamp() ?? time(),
					'latitude' => $dto->latitude,
					'longitude' => $dto->longitude,
				];
			}

			$batchState = $statsUpdater->updateBatch(
				$dtos[0]->userId,
				$points,
				(int)($dtos[0]->userTimezone ?? 0),
			);

			$connection->commitTransaction();
		}
		catch (\Throwable $e)
		{
			$connection->rollbackTransaction();

			AddMessage2Log('AddBatch::saveBatch error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());

			return $result->addError(new Error('Failed to save check-in batch'));
		}

		// A rolled back batch never reaches this point, and the delta only reads, so it is built after
		// the commit: the transaction holds no day-wide read.
		if ($isDeltaNeeded)
		{
			$this->batchDelta = $statsUpdater->buildBatchDelta($batchState);
		}

		return $result;
	}

	/**
	 * @param CheckInDto[] $dtos
	 */
	private function prepareResults(array $dtos): array
	{
		$results = [];
		foreach ($dtos as $dto)
		{
			$checkInData = [
				'ID' => $dto->id,
				'USER_ID' => $dto->userId,
				'ENTITY_TYPE' => $dto->entityType,
				'LATITUDE' => $dto->latitude,
				'LONGITUDE' => $dto->longitude,
				'DATE_CREATE' => $dto->dateCreate,
				'DESCRIPTION' => $dto->description,
				'ADDRESS' => $dto->address,
			];

			$results[] = CheckInNormalizer::normalize(
				$checkInData,
				(int)($dto->userTimezone ?? 0),
			);
		}

		return $results;
	}
}
