<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Messenger\Receiver;

use Bitrix\Main\Messenger\Entity\MessageInterface;
use Bitrix\Main\Messenger\Internals\Exception\Receiver\RecoverableMessageException;
use Bitrix\Main\Messenger\Internals\Exception\Receiver\UnprocessableMessageException;
use Bitrix\Main\Messenger\Internals\Exception\Receiver\UnrecoverableMessageException;
use Bitrix\Main\Messenger\Receiver\AbstractReceiver;
use Bitrix\Vibecodeconnector\Internal\Entity\User\UserEventTarget;
use Bitrix\Vibecodeconnector\Internal\Service\Messenger\Message\UserEventMessage;
use Bitrix\Vibecodeconnector\Internal\Service\User\UserEventRateLimiter;
use Bitrix\Vibecodeconnector\Internal\Service\User\UserEventRetryPolicy;
use Bitrix\Vibecodeconnector\Internal\Service\User\UserEventTargetProvider;
use Bitrix\Vibecodeconnector\Internal\Service\User\UserEventsAvailability;
use Bitrix\Vibecodeconnector\Internal\Service\User\Vibecode\UserEventClient;

class UserEventReceiver extends AbstractReceiver
{
	private const LIFETIME_SECONDS = 86400;
	private const PREPARE_RETRY_DELAY_SECONDS = 1;

	public function __construct(
		private readonly UserEventTargetProvider $targetProvider = new UserEventTargetProvider(),
		private readonly UserEventRateLimiter $rateLimiter = new UserEventRateLimiter(),
		private readonly UserEventRetryPolicy $retryPolicy = new UserEventRetryPolicy(),
		private readonly UserEventsAvailability $userEventsAvailability = new UserEventsAvailability(),
	) {
	}

	protected function process(MessageInterface $message): void
	{
		if (!$message instanceof UserEventMessage)
		{
			throw new UnprocessableMessageException($message);
		}

		if (!$this->userEventsAvailability->isEnabled())
		{
			return;
		}

		$target = $this->resolveTarget($message->pairingIss);
		if ($target === null)
		{
			return;
		}

		$now = $this->getCurrentTime();
		if ($message->firstAttemptAt === null)
		{
			if ($now >= $message->enqueuedAt + self::LIFETIME_SECONDS)
			{
				throw new UnrecoverableMessageException('User event delivery window expired before first attempt');
			}

			$message->firstAttemptAt = $now;
			$message->attemptCount = 1;
			$message->attemptPrepared = true;

			throw new RecoverableMessageException(
				message: 'User event attempt was prepared',
				retryDelay: self::PREPARE_RETRY_DELAY_SECONDS,
			);
		}

		$deadline = $message->firstAttemptAt + self::LIFETIME_SECONDS;
		if ($now >= $deadline)
		{
			throw new UnrecoverableMessageException('User event delivery window expired');
		}

		$rateLimitDelay = $this->rateLimiter->reserve($message->pairingIss, $now);
		if ($rateLimitDelay > 0)
		{
			throw new RecoverableMessageException(
				message: 'User event rate limit is unavailable',
				retryDelay: $rateLimitDelay,
			);
		}

		if ($message->attemptPrepared)
		{
			$message->attemptPrepared = false;
		}
		else
		{
			$message->attemptCount++;
		}

		try
		{
			$client = $this->createClient($target->endpointUrl);
			$result = $client->sendEvent(
				$message->bitrixUserId,
				$message->change,
				$message->occurredAt,
				$message->isAdmin,
				$message->isIntegrator,
				$message->groupEventSequence,
			);
		}
		catch (\Throwable)
		{
			$this->retry($deadline, $now, null, null, 'event_transport_retry');
		}

		if (!$result->isSuccess())
		{
			$this->handleFailedResult($message, $client, $result->getErrors(), $deadline, $now);
		}

		$data = $result->getData();
		$accepted = $data['accepted'] ?? null;
		$skipped = $data['skipped'] ?? null;
		if (
			!is_int($accepted)
			|| !is_int($skipped)
			|| $accepted < 0
			|| $skipped < 0
			|| $accepted + $skipped !== 1
		)
		{
			$this->retry(
				$deadline,
				$now,
				null,
				$client->getLastHttpStatus(),
				'event_protocol_retry',
			);
		}
	}

	protected function resolveTarget(string $iss): ?UserEventTarget
	{
		return $this->targetProvider->resolve($iss);
	}

	protected function getCurrentTime(): int
	{
		return time();
	}

	protected function createClient(string $endpointUrl): UserEventClient
	{
		return new UserEventClient($endpointUrl);
	}

	/**
	 * @param \Bitrix\Main\Error[] $errors
	 */
	private function handleFailedResult(
		UserEventMessage $message,
		UserEventClient $client,
		array $errors,
		int $deadline,
		int $now,
	): void {
		$httpStatus = $client->getLastHttpStatus();
		if ($httpStatus === null || $httpStatus !== 200)
		{
			$this->retry($deadline, $now, null, $httpStatus, 'event_transport_retry');
		}

		$firstError = $errors[0] ?? null;
		$errorCode = $firstError !== null ? (string)$firstError->getCode() : 'PROTOCOL_ERROR';
		if ($errorCode === 'INVALID_PAYLOAD')
		{
			throw new UnrecoverableMessageException('Invalid user.event payload');
		}

		$retryAfterSeconds = null;
		if ($errorCode === 'RATE_LIMITED')
		{
			$customData = $firstError?->getCustomData();
			$retryAfterSeconds = is_array($customData) ? ($customData['retryAfterSeconds'] ?? null) : null;
		}

		$this->retry(
			$deadline,
			$now,
			$retryAfterSeconds,
			$httpStatus,
			$errorCode === 'RATE_LIMITED' ? 'event_rate_limited' : 'event_protocol_retry',
		);
	}

	private function retry(
		int $deadline,
		int $now,
		mixed $retryAfterSeconds,
		?int $httpStatus,
		string $eventCode,
	): never {
		throw new RecoverableMessageException(
			message: sprintf(
				'User event delivery will be retried: %s, HTTP status: %s',
				$eventCode,
				$httpStatus === null ? 'none' : (string)$httpStatus,
			),
			retryDelay: $this->retryPolicy->getRetryDelay($retryAfterSeconds, $now, $deadline),
		);
	}
}
