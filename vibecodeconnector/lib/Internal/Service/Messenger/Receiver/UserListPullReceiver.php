<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Messenger\Receiver;

use Bitrix\Main\Application;
use Bitrix\Main\Messenger\Entity\MessageInterface;
use Bitrix\Main\Messenger\Internals\Exception\Receiver\RecoverableMessageException;
use Bitrix\Main\Messenger\Internals\Exception\Receiver\UnprocessableMessageException;
use Bitrix\Main\Messenger\Internals\Exception\Receiver\UnrecoverableMessageException;
use Bitrix\Main\Messenger\Receiver\AbstractReceiver;
use Bitrix\Vibecodeconnector\Internal\Entity\User\UserEventTarget;
use Bitrix\Vibecodeconnector\Internal\Service\Messenger\Message\UserListPullMessage;
use Bitrix\Vibecodeconnector\Internal\Service\Messenger\UserListPullQueueGuard;
use Bitrix\Vibecodeconnector\Internal\Service\User\UserEventTargetProvider;
use Bitrix\Vibecodeconnector\Internal\Service\User\UserEventsAvailability;
use Bitrix\Vibecodeconnector\Internal\Service\User\UserListRetryPolicy;
use Bitrix\Vibecodeconnector\Internal\Service\User\UserListSyncService;
use Bitrix\Vibecodeconnector\Internal\Service\User\Vibecode\UserListClient;

class UserListPullReceiver extends AbstractReceiver
{
	private const LOCK_PREFIX = 'vibecodeconnector.user_list_pull.';
	private const LOCK_RETRY_DELAY = 5;
	private const TERMINAL_ERROR_CODES = [
		'FEATURE_DISABLED',
		'UNKNOWN_ACTION',
		'NOT_REGISTERED',
		'PORTAL_SUSPENDED',
	];

	public function __construct(
		private readonly UserEventTargetProvider $targetProvider = new UserEventTargetProvider(),
		private readonly UserListSyncService $syncService = new UserListSyncService(),
		private readonly UserListRetryPolicy $retryPolicy = new UserListRetryPolicy(),
		private readonly UserListPullQueueGuard $queueGuard = new UserListPullQueueGuard(),
		private readonly UserEventsAvailability $userEventsAvailability = new UserEventsAvailability(),
	) {
	}

	protected function process(MessageInterface $message): void
	{
		if (!$message instanceof UserListPullMessage)
		{
			throw new UnprocessableMessageException($message);
		}

		if (!$this->userEventsAvailability->isEnabled())
		{
			try
			{
				$this->queueGuard->discard($message);
			}
			catch (\Throwable $exception)
			{
				throw new RecoverableMessageException(
					message: 'Unable to discard disabled user list pull',
					previous: $exception,
					retryDelay: self::LOCK_RETRY_DELAY,
				);
			}

			return;
		}

		try
		{
			$target = $this->resolveTarget($message->pairingIss);
			if ($target === null || $target->endpointUrl !== $message->endpointUrl)
			{
				$this->queueGuard->release($message);

				return;
			}

			$this->pull($message);
			$this->queueGuard->complete($message);
		}
		catch (RecoverableMessageException $exception)
		{
			$this->queueGuard->refresh($message);

			throw $exception;
		}
		catch (UnrecoverableMessageException $exception)
		{
			$this->queueGuard->release($message);

			throw $exception;
		}
		catch (\Throwable $exception)
		{
			$this->queueGuard->release($message);

			throw $exception;
		}
	}

	private function pull(UserListPullMessage $message): void
	{
		$lockName = self::LOCK_PREFIX . hash('sha256', $message->pairingIss);
		if (!$this->acquireLock($lockName))
		{
			throw new RecoverableMessageException(
				message: 'User list pull is already in progress',
				retryDelay: self::LOCK_RETRY_DELAY,
			);
		}

		try
		{
			$message->attemptCount++;
			$client = $this->createClient($message->endpointUrl);
			$result = $client->fetch();

			if (!$result->isSuccess())
			{
				$this->handleFailedResult($message, $client, $result->getErrors());

				return;
			}

			try
			{
				$this->syncService->sync($result->getData());
			}
			catch (\UnexpectedValueException $exception)
			{
				throw new UnrecoverableMessageException('Invalid user.list payload', previous: $exception);
			}
		}
		finally
		{
			$this->releaseLock($lockName);
		}
	}

	protected function createClient(string $endpointUrl): UserListClient
	{
		return new UserListClient($endpointUrl);
	}

	protected function resolveTarget(string $iss): ?UserEventTarget
	{
		return $this->targetProvider->resolve($iss);
	}

	protected function acquireLock(string $lockName): bool
	{
		return Application::getConnection()->lock($lockName, 0);
	}

	protected function releaseLock(string $lockName): void
	{
		Application::getConnection()->unlock($lockName);
	}

	/**
	 * @param \Bitrix\Main\Error[] $errors
	 */
	private function handleFailedResult(
		UserListPullMessage $message,
		UserListClient $client,
		array $errors,
	): void {
		$httpStatus = $client->getLastHttpStatus();
		if ($httpStatus === null || $httpStatus !== 200)
		{
			throw new RecoverableMessageException(
				message: sprintf(
					'User list transport failed, HTTP status: %s',
					$httpStatus === null ? 'none' : (string)$httpStatus,
				),
				retryDelay: $this->retryPolicy->forAttempt($message->attemptCount),
			);
		}

		$firstError = $errors[0] ?? null;
		$errorCode = $firstError !== null ? (string)$firstError->getCode() : 'INVALID_PAYLOAD';

		if (in_array($errorCode, self::TERMINAL_ERROR_CODES, true))
		{
			throw new UnrecoverableMessageException(sprintf(
				'User list request failed permanently: %s',
				$errorCode,
			));
		}

		if ($errorCode === 'RATE_LIMITED')
		{
			$customData = $firstError?->getCustomData();
			$retryAfterSeconds = is_array($customData) ? ($customData['retryAfterSeconds'] ?? null) : null;
			$retryDelay = $this->retryPolicy->forRateLimit($retryAfterSeconds);
			throw new RecoverableMessageException(
				message: 'User list request was rate limited',
				retryDelay: $retryDelay,
			);
		}

		throw new UnrecoverableMessageException('Invalid user.list response');
	}
}
