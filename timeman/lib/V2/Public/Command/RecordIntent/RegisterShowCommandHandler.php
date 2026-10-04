<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Public\Command\RecordIntent;

use Bitrix\Main\Result;
use Bitrix\Timeman\V2\Internal\Repository\RecordIntentStateRepository;
use Bitrix\Timeman\V2\Internal\Service\RecordIntentService;
use Bitrix\Timeman\V2\Public\Dto\RecordIntent\RecordIntentSchedule;

class RegisterShowCommandHandler
{
	public function __construct(
		private readonly RecordIntentService $service,
		private readonly RecordIntentStateRepository $stateRepository,
	)
	{
	}

	public function __invoke(RegisterShowCommand $command): Result
	{
		// Re-resolve the period so the show guard uses the same effective maxShows that getData served
		// (WEEKEND_MAX_SHOWS for a fixed-schedule weekend day).
		$envelope = $this->service->resolveSchedule($command->userId);

		$registerResult = $this->stateRepository->registerShow(
			$command->userId,
			$command->periodKey,
			(int)$envelope['maxShows'],
			(int)$envelope['periodEndTimestamp'],
		);

		if (!$registerResult->isSuccess())
		{
			return $registerResult;
		}

		$result = new Result();
		$result->setData([
			'schedule' => RecordIntentSchedule::mapFromArray($this->service->resolveSchedule($command->userId)),
		]);

		return $result;
	}
}
