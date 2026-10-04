<?php

namespace Bitrix\Rest\Internal\Service\Messenger\DeferredBatch;

use Bitrix\Main\Context;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Loader;
use Bitrix\Main\Messenger\Entity\MessageInterface;
use Bitrix\Main\Messenger\Internals\Exception\Receiver\UnprocessableMessageException;
use Bitrix\Main\Messenger\Receiver\AbstractReceiver;
use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\Internal\Entity\DeferredBatch;
use Bitrix\Rest\Internal\Entity\DeferredBatch\Status;
use Bitrix\Rest\Internal\Model\DeferredBatch\Params;
use Bitrix\Rest\Internal\Repository\DeferredBatchRepository;
use Bitrix\Rest\Internal\Service\DeferredBatch\FileStorage;
use Bitrix\Rest\V3\DeferredRestApiServer;
use Bitrix\Rest\V3\Interaction\Request\ServerRequest;

final class Receiver extends AbstractReceiver
{
	private const LEASE_SECONDS = 600;

	public function __construct(
		private readonly DeferredBatchRepository $repository = new DeferredBatchRepository(),
		private readonly FileStorage $fileStorage = new FileStorage()
	)
	{
	}

	protected function process(MessageInterface $message): void
	{
		if (!Loader::includeModule('rest'))
		{
			return;
		}

		if (!$message instanceof Message)
		{
			throw new UnprocessableMessageException(
				$message,
				'Expected ' . Message::class,
			);
		}

		$leaseExpiredBefore = (new DateTime())->add('-' . self::LEASE_SECONDS . ' seconds');
		$record = $this->repository->claimForProcessing($message->commandId, $leaseExpiredBefore);
		if ($record === null)
		{
			// Missing, done, error, or still within an active processing lease.
			return;
		}

		$this->execute($record);
	}

	private function execute(DeferredBatch $deferredBatch): void
	{
		global $USER;

		try
		{
			$params = $deferredBatch->getParams();

			$server = new DeferredRestApiServer([
				'RESPONSE_LANGUAGE' => $params[Params::Language->value] ?? null,
				'METHOD' => 'batch',
			]);

			$USER = new \CUser();

			$server->setAvailableScopes($deferredBatch->getScopes());
			$batchHttpRequest = new HttpRequest(
				Context::getCurrent()->getServer(),
				[],
				[],
				[],
				[],
				$deferredBatch->getCommands(),
			);

			$query = $params[Params::Query->value] ?? [];

			$serverRequest = new ServerRequest('batch', $query, $batchHttpRequest);

			$serverResult = $server->processServerRequest($serverRequest);

			if (!isset($serverResult['result']))
			{
				$this->finalize($deferredBatch, Status::Error, 'Result does not exists');

				return;
			}

			// Commands have already been executed. Persist the result without re-running them.
			try
			{
				$fileId = $this->fileStorage->write($deferredBatch->getId(), $serverResult['result']);
				$this->finalize($deferredBatch, Status::Done, null, $fileId);
			}
			catch (\Throwable $e)
			{
				$this->finalize($deferredBatch, Status::Error, 'Failed to save result: ' . $e->getMessage());
			}
		}
		catch (\Throwable $e)
		{
			$this->finalize($deferredBatch, Status::Error, $e->getMessage());
		}
		finally
		{
			if (isset($server))
			{
				$server->finalize();
			}

			$USER = null;
		}
	}

	private function finalize(
		DeferredBatch $deferredBatch,
		Status $status,
		?string $errorMessage,
		?int $resultFileId = null,
	): void
	{
		$deferredBatch->setStatus($status);
		$deferredBatch->setErrorMessage($errorMessage);
		if ($resultFileId !== null)
		{
			$deferredBatch->setResultFileId($resultFileId);
		}
		// Drop auth/query payload once execution finished so secrets do not linger in DB.
		$deferredBatch->setParams(null);
		$this->repository->save($deferredBatch);
	}
}
