<?php

namespace Bitrix\StaffTrack\Public\Services;

use Bitrix\Main\Result;
use Bitrix\StaffTrack\Public\Command\CheckInDto;
use Bitrix\StaffTrack\Public\Command\CheckIn\Add;
use Bitrix\StaffTrack\Public\Command\CheckIn\AddBatch;

class CheckInService
{
	/**
	 * Authoritative upper bound on a single batch, re-exposed from {@see AddBatch::MAX_BATCH_SIZE}
	 * so transport consumers can reference the limit through this public facade instead of
	 * duplicating the value.
	 */
	public const MAX_BATCH_SIZE = AddBatch::MAX_BATCH_SIZE;

	private static ?self $instance = null;

	public static function getInstance(): self
	{
		if (self::$instance === null)
		{
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function add(CheckInDto $checkInDto, array $uploadedFiles = []): Result
	{
		return (new Add())->execute($checkInDto, $uploadedFiles);
	}

	/**
	 * Batch write-path for accumulated auto check-in points submitted in a single call.
	 *
	 * @param CheckInDto[] $checkInDtos Pre-assembled DTOs (userId/timezone forced by the caller).
	 */
	public function addBatch(array $checkInDtos): Result
	{
		return (new AddBatch())->executeBatch($checkInDtos);
	}
}
