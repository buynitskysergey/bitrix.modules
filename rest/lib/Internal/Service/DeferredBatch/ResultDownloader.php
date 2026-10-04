<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service\DeferredBatch;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Engine\Response\BFile;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Rest\Internal\Entity\DeferredBatch\Status;
use Bitrix\Rest\Internal\Repository\DeferredBatchRepository;
use Bitrix\Rest\RestException;
use CRestServer;

/**
 * Authorized download of deferred batch result files via signed /rest/download links.
 */
class ResultDownloader
{
	public static function download(array $query, $scope, CRestServer $restServer): BFile
	{
		$batchId = (int)($query['deferredBatchId'] ?? 0);
		if ($batchId <= 0)
		{
			throw new RestException('Deferred batch ID not specified', 'INVALID_BATCH_ID');
		}

		$userId = (int)CurrentUser::get()->getId();
		if ($userId <= 0)
		{
			throw new RestException('Access denied', 'ACCESS_DENIED', CRestServer::STATUS_FORBIDDEN);
		}

		$repository = new DeferredBatchRepository();
		$batch = $repository->getById($batchId, ['ID', 'USER_ID', 'STATUS', 'RESULT_FILE_ID']);
		if (
			$batch === null
			|| (int)$batch->getUserId() !== $userId
			|| $batch->getStatus() !== Status::Done
			|| $batch->getResultFileId() === null
		)
		{
			throw new RestException('Deferred batch result not found', 'BATCH_RESULT_NOT_FOUND', CRestServer::STATUS_NOT_FOUND);
		}

		try
		{
			return BFile::createByFileId((int)$batch->getResultFileId());
		}
		catch (ObjectNotFoundException)
		{
			throw new RestException('Result file not found', 'FILE_NOT_FOUND', CRestServer::STATUS_NOT_FOUND);
		}
	}
}
