<?php

namespace Bitrix\StaffTrack\Public\Command\CheckIn;

use Bitrix\Main\Loader;
use Bitrix\Main\Application;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Error;
use Bitrix\Main\LoaderException;
use Bitrix\StaffTrack\Internals\Exception\InvalidDtoException;
use Bitrix\StaffTrack\Internal\Integration\Disk\FileSaver;
use Bitrix\StaffTrack\Public\Command\CheckInDto;
use Bitrix\StaffTrack\Public\Provider\CheckInNormalizer;
use Bitrix\StaffTrack\Internal\Entity\CheckInType;
use Bitrix\StaffTrack\Internal\Integration\Im\MessageService;
use Bitrix\StaffTrack\Internal\Integration\Pull;
use Bitrix\StaffTrack\Internal\Integration\Timeman\WorkDayService;
use Bitrix\Main\Text\Emoji;
use Bitrix\StaffTrack\Internal\Geo\Geohash;
use Bitrix\StaffTrack\Public\Services\AddressCache;
use Bitrix\StaffTrack\Public\Services\DailyStatsUpdater;
use Bitrix\StaffTrack\Public\Services\FirstManualCheckInCache;

class Add extends AbstractCommand
{
	private array $addDelta = [];

	public function execute(CheckInDto $checkInDto, array $uploadedFiles = []): Result
	{
		$result = new Result();

		if (!Loader::includeModule('location'))
		{
			return $result->addError(new Error('Module "location" not installed'));
		}

		$this->checkInDto = clone $checkInDto;
		$this->checkInDto->setId(0);

		try
		{
			$this->checkInDto->validateAdd();
		}
		catch (InvalidDtoException $e)
		{
			return $result->addError(new Error($e->getMessage()));
		}

		// Before the message is sent and the files are saved: a point refused by the window must leave
		// nothing behind.
		$dateError = self::fitDateCreateToWindow($this->checkInDto);
		if ($dateError !== null)
		{
			return $result->addError($dateError);
		}

		$this->normalizeFileIds();
		$this->processUploadedFiles($uploadedFiles);
		$this->fillAddressFromCache();

		$this->checkInDto->messageIds = [];

		if ($this->checkInDto->dialogIds && $this->checkInDto->snapshotUrl)
		{
			$sendResult = $this->tryToSendMessage();
			if (!$sendResult->isSuccess())
			{
				return $result->addErrors($sendResult->getErrors());
			}

			$this->checkInDto->messageIds = $sendResult->getData()['messageIds'];
		}

		$this->checkInDto->description = Emoji::encode($this->checkInDto->description);

		$saveResult = $this->saveCheckIn();
		if (!$saveResult->isSuccess())
		{
			return $result->addErrors($saveResult->getErrors());
		}

		MessageService::saveLastSelectedDialogId($this->checkInDto->userId, $this->checkInDto->dialogIds ?? []);
		if (
			$this->checkInDto->entityType === CheckInType::MANUAL->value
			&& !$this->checkInDto->skipWorkDayStart
		)
		{
			WorkDayService::startWorkDay();
		}
		$this->sendPushToDepartment(Pull\PushCommand::CHECK_IN_ADD, $this->addDelta);

		return $result->setData([
			'checkIn' => $this->prepareResult(),
		]);
	}

	/**
	 * @throws LoaderException
	 */
	private function tryToSendMessage(): Result
	{
		return MessageService::send($this->checkInDto);
	}

	private function normalizeFileIds(): void
	{
		$this->checkInDto->setFileIds(
			FileSaver::normalizeFileIds($this->checkInDto->fileIds),
		);
	}

	private function processUploadedFiles(array $uploadedFiles): void
	{
		if (empty($uploadedFiles))
		{
			return;
		}

		$saveResult = (new FileSaver())->saveUploadedFiles($uploadedFiles, $this->checkInDto->userId);
		if (!$saveResult->isSuccess())
		{
			return;
		}

		$newFileIds = $saveResult->getData()['fileIds'];
		if (!empty($newFileIds))
		{
			$this->checkInDto->setFileIds(
				array_merge($this->checkInDto->fileIds, $newFileIds),
			);
		}
	}

	private function fillAddressFromCache(): void
	{
		if (!empty($this->checkInDto->address))
		{
			return;
		}

		$geohash = Geohash::encode(
			(float)$this->checkInDto->latitude,
			(float)$this->checkInDto->longitude,
		);
		$address = AddressCache::get($geohash);

		if ($address !== null)
		{
			$this->checkInDto->setAddress($address);
		}
	}

	private function saveCheckIn(): Result
	{
		$result = new Result();

		if ($this->checkInDto->dateCreate === null)
		{
			$this->checkInDto->setDateCreate(new DateTime());
		}

		// Resolving the rollout flags can walk the department tree, so it is done before the transaction.
		$isDeltaNeeded = $this->isDeltaNeededByRecipients($this->checkInDto->userId);
		$statsUpdater = new DailyStatsUpdater();

		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$checkIn = $this->mapper->createEntityFromDto($this->checkInDto);
			$saveResult = $checkIn->save();
			if (!$saveResult->isSuccess())
			{
				$connection->rollbackTransaction();

				return $result->addErrors($saveResult->getErrors());
			}

			$this->checkInDto->id = (int)$saveResult->getId();
			$addState = $this->applyDailyStats($statsUpdater);
			$connection->commitTransaction();

			if ($this->checkInDto->entityType === CheckInType::MANUAL->value)
			{
				FirstManualCheckInCache::invalidate($this->checkInDto->userId);
			}
		}
		catch (\Throwable $e)
		{
			// the daily-stats write fails a unique-index race with a SystemException, which is a parent
			// of SqlException: a narrower catch would leave the transaction open and return no Result
			$connection->rollbackTransaction();

			AddMessage2Log('Add::saveCheckIn error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());

			return $result->addError(new Error('Failed to save check-in'));
		}

		// The delta only reads, and a rolled back check-in never reaches this point, so it is built
		// after the commit: the transaction holds no day-wide read or aggregation.
		if ($isDeltaNeeded)
		{
			$this->addDelta = $statsUpdater->buildAddDelta($addState);
		}

		return $result;
	}

	private function applyDailyStats(DailyStatsUpdater $statsUpdater): array
	{
		$timestamp = $this->checkInDto->dateCreate?->getTimestamp() ?? time();

		return $statsUpdater->applyAdd(
			$this->checkInDto->userId,
			$this->checkInDto->id,
			$this->checkInDto->entityType,
			(string)($this->checkInDto->description ?? ''),
			(int)($this->checkInDto->userTimezone ?? 0),
			$timestamp,
		);
	}

	private function prepareResult(): array
	{
		$checkInData = [
			'ID' => $this->checkInDto->id,
			'USER_ID' => $this->checkInDto->userId,
			'ENTITY_TYPE' => $this->checkInDto->entityType,
			'LATITUDE' => $this->checkInDto->latitude,
			'LONGITUDE' => $this->checkInDto->longitude,
			'DATE_CREATE' => $this->checkInDto->dateCreate,
			'DESCRIPTION' => $this->checkInDto->description,
			'ADDRESS' => $this->checkInDto->address,
		];

		$normalized = CheckInNormalizer::normalize(
			$checkInData,
			(int)($this->checkInDto->userTimezone ?? 0),
		);

		return $normalized;
	}
}
