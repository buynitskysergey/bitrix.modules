<?php

namespace Bitrix\Rest\Infrastructure\Agent\DeferredBatch;

use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\Internal\Entity\DeferredBatch;
use Bitrix\Rest\Internal\Entity\DeferredBatch\Status;
use Bitrix\Rest\Internal\Repository\DeferredBatchRepository;
use Bitrix\Rest\Internal\Service\DeferredBatch\FileStorage;

class Cleanup
{
	private const RETENTION_DAYS = 30;
	private const BATCH_SIZE = 100;
	private const MAX_BATCHES = 50;

	public static function execute(): string
	{
		$threshold = new DateTime();
		$threshold->add('-' . self::RETENTION_DAYS . ' day');

		$finishedStatuses = [
			Status::Done->value,
			Status::Error->value,
		];

		$repository = new DeferredBatchRepository();
		$fileStorage = new FileStorage();

		for ($batch = 0; $batch < self::MAX_BATCHES; $batch++)
		{
			/** @var DeferredBatch[] $rows */
			$rows = $repository->getAllForDelete($finishedStatuses, $threshold, self::BATCH_SIZE);
			if ($rows === [])
			{
				break;
			}

			foreach ($rows as $row)
			{
				if ($row->getResultFileId() !== null)
				{
					$fileStorage->delete($row->getResultFileId());
				}

				$repository->delete($row->getId());
			}
		}

		return '\Bitrix\Rest\Infrastructure\Agent\DeferredBatch\Cleanup::execute();';
	}
}
