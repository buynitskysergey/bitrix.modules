<?php

namespace Bitrix\Rest\Internal\Service;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Rest\Internal\Entity\DeferredBatch;
use Bitrix\Rest\Internal\Entity\DeferredBatch\Status;
use Bitrix\Rest\Internal\Repository\DeferredBatchRepository;
use Bitrix\Rest\Internal\Service\DeferredBatch\ScopeValidator;
use Bitrix\Rest\Internal\Service\Messenger\DeferredBatch\Message;
use Bitrix\Rest\V3\Interaction\Request\BatchRequest;

/**
 * Registers a deferred batch command.
 *
 * Validates scopes for all commands, persists the record and enqueues it.
 */
readonly class DeferredBatchService
{
	public function __construct(
		private ScopeValidator $scopeValidator = new ScopeValidator(),
		private DeferredBatchRepository $repository = new DeferredBatchRepository()
	)
	{
	}

	/**
	 * Registers a new deferred batch command.
	 *
	 * @param array $commands        Raw commands array (same format as V3 batch).
	 * @param int   $userId          Caller's user ID.
	 * @param array $availableScopes Scope strings available to the caller.
	 *
	 * @return Result Contains ['id' => int] on success.
	 */
	public function register(array $commands, int $userId, array $availableScopes, ?array $params = null): Result
	{
		$result = new Result();

		// 1. Parse commands as BatchRequest (validates structure).
		try
		{
			$batchRequest = new BatchRequest($commands);
		}
		catch (\Throwable $e)
		{
			$result->addError(new Error($e->getMessage(), 'INVALID_COMMANDS'));

			return $result;
		}

		// 2. Validate scopes for all commands.
		$scopeResult = $this->scopeValidator->validate($batchRequest, $availableScopes);
		if (!$scopeResult->isSuccess())
		{
			foreach ($scopeResult->getErrorCollection() as $error)
			{
				$result->addError($error);
			}

			return $result;
		}

		// 3. Persist record via repository.
		$entity = new DeferredBatch(
			userId: $userId,
			status: Status::Pending,
			commands: $commands,
			scopes: array_values($availableScopes),
		);

		if ($params !== null && !empty($params))
		{
			$entity->setParams($params);
		}

		try
		{
			$this->repository->save($entity);
		}
		catch (\Throwable $e)
		{
			$result->addError(new Error($e->getMessage(), 'DB_ERROR'));

			return $result;
		}

		$id = $entity->getId();

		if ($id === null)
		{
			$result->addError(new Error('Failed to persist deferred batch record', 'DB_ERROR'));

			return $result;
		}

		// 4. Enqueue the command.
		try
		{
			(new Message($id))->send(Message::QUEUE_ID);
		}
		catch (\Throwable $e)
		{
			// Roll back the record if we can't enqueue.
			$this->repository->delete($id);
			$result->addError(new Error($e->getMessage(), 'QUEUE_ERROR'));

			return $result;
		}

		$result->setData(['id' => $id]);

		return $result;
	}
}
