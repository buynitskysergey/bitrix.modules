<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\User;

use Bitrix\Vibecodeconnector\Internal\Entity\User\UserEventChange;
use Bitrix\Vibecodeconnector\Internal\Entity\User\UserEventTarget;
use Bitrix\Vibecodeconnector\Internal\Repository\User\UserAttributesRepository;
use Bitrix\Vibecodeconnector\Internal\Repository\User\UserRepository;
use Bitrix\Vibecodeconnector\Internal\Service\Messenger\Message\UserEventMessage;
use Bitrix\Vibecodeconnector\Internal\Service\Messenger\UserEventQueueCapacityGuard;

class UserEventPublisher
{
	public function __construct(
		private readonly UserRepository $userRepository = new UserRepository(),
		private readonly UserEventTargetProvider $targetProvider = new UserEventTargetProvider(),
		private readonly UserEventQueueCapacityGuard $capacityGuard = new UserEventQueueCapacityGuard(),
		private readonly UserAttributesRepository $userAttributesRepository = new UserAttributesRepository(),
		private readonly UserEventsAvailability $userEventsAvailability = new UserEventsAvailability(),
	) {
	}

	public function publish(int $bitrixUserId, UserEventChange $change): void
	{
		if ($change === UserEventChange::GroupsChanged)
		{
			throw new \InvalidArgumentException('Use publishGroupsChanged for groups changed events');
		}

		if (!$this->hasUser($bitrixUserId))
		{
			return;
		}

		$now = $this->getCurrentTime();
		$this->enqueueToAllTargets($bitrixUserId, $change, $now, $now);
	}

	public function publishGroupsChanged(int $userId, bool $isAdmin, bool $isIntegrator): void
	{
		if (!$this->hasUser($userId))
		{
			return;
		}

		$groupEventSequence = $this->userAttributesRepository->incrementGroupEventSequence($userId);
		$now = $this->getCurrentTime();
		$this->enqueueToAllTargets(
			$userId,
			UserEventChange::GroupsChanged,
			$now,
			$now,
			$isAdmin,
			$isIntegrator,
			$groupEventSequence,
		);
	}

	public function hasUser(int $bitrixUserId): bool
	{
		return $this->userEventsAvailability->isEnabled()
			&& $bitrixUserId > 0
			&& $this->userRepository->contains($bitrixUserId);
	}

	protected function getCurrentTime(): int
	{
		return time();
	}

	/**
	 * @return list<UserEventTarget>
	 */
	protected function listTargets(): array
	{
		return $this->targetProvider->listAll();
	}

	private function enqueueToAllTargets(
		int $bitrixUserId,
		UserEventChange $change,
		int $occurredAt,
		int $enqueuedAt,
		?bool $isAdmin = null,
		?bool $isIntegrator = null,
		?int $groupEventSequence = null,
	): void {
		$failedCount = 0;
		$firstFailure = null;

		foreach ($this->listTargets() as $target)
		{
			$message = new UserEventMessage(
				pairingIss: $target->iss,
				bitrixUserId: $bitrixUserId,
				change: $change,
				occurredAt: $occurredAt,
				enqueuedAt: $enqueuedAt,
				isAdmin: $isAdmin,
				isIntegrator: $isIntegrator,
				groupEventSequence: $groupEventSequence,
			);

			try
			{
				if (!$this->capacityGuard->trySend($message))
				{
					$failedCount++;
					$firstFailure ??= new \RuntimeException('User event queue capacity is unavailable');
				}
			}
			catch (\Throwable $exception)
			{
				$failedCount++;
				$firstFailure ??= $exception;
			}
		}

		if ($failedCount > 0)
		{
			throw new \RuntimeException(sprintf(
				'Failed to enqueue user event for %d pairing(s)',
				$failedCount,
			), previous: $firstFailure);
		}
	}
}
