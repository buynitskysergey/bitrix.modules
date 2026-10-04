<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Public\Command\RecordIntent;

use Bitrix\Main\Result;
use Bitrix\Timeman\V2\Internal\Repository\RecordIntentStateRepository;
use Bitrix\Timeman\V2\Internal\Service\RecordIntentService;
use Bitrix\Timeman\V2\Public\Dto\RecordIntent\RecordIntentSchedule;

class RegisterOutcomeCommandHandler
{
	public function __construct(
		private readonly RecordIntentService $service,
		private readonly RecordIntentStateRepository $stateRepository,
	)
	{
	}

	public function __invoke(RegisterOutcomeCommand $command): Result
	{
		$envelope = $this->service->resolveSchedule($command->userId);

		$registerResult = $this->stateRepository->registerOutcome(
			$command->userId,
			$command->periodKey,
			$command->outcome,
			RecordIntentService::MAX_DISMISSES,
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
