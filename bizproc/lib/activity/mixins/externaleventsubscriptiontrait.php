<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Activity\Mixins;

trait ExternalEventSubscriptionTrait
{
	private ?string $eventSubscriptionHash = null;
	private int $eventSubscriptionTimeoutId = 0;

	protected function subscribeOnExternalEvents(
		string $moduleId,
		array $eventNames,
		string $hash,
		int $timeoutSeconds = 0,
	): void
	{
		$this->eventSubscriptionHash = $hash;

		$schedulerService = $this->workflow->getService('SchedulerService');
		foreach ($eventNames as $eventName)
		{
			$schedulerService->subscribeOnEvent(
				$this->workflow->getInstanceId(),
				$this->name,
				$moduleId,
				$eventName,
				$hash,
			);
		}

		if ($timeoutSeconds > 0)
		{
			$this->eventSubscriptionTimeoutId = (int)$schedulerService->subscribeOnTime(
				$this->workflow->getInstanceId(),
				$this->name,
				time() + $timeoutSeconds,
			);
		}
	}

	protected function unsubscribeFromExternalEvents(
		string $moduleId,
		array $eventNames,
	): void
	{
		$schedulerService = $this->workflow->getService('SchedulerService');
		foreach ($eventNames as $eventName)
		{
			$schedulerService->unSubscribeOnEvent(
				$this->workflow->getInstanceId(),
				$this->name,
				$moduleId,
				$eventName,
				$this->eventSubscriptionHash,
			);
		}

		if ($this->eventSubscriptionTimeoutId > 0)
		{
			$schedulerService->unSubscribeOnTime($this->eventSubscriptionTimeoutId);
			$this->eventSubscriptionTimeoutId = 0;
		}

		$this->eventSubscriptionHash = null;
	}

	protected function switchExternalEventSubscriptionTrait(
		string $moduleId,
		array $eventNames,
		string $newHash,
		int $timeoutSeconds = 0,
	): void
	{
		$this->unsubscribeFromExternalEvents($moduleId, $eventNames);
		$this->subscribeOnExternalEvents($moduleId, $eventNames, $newHash, $timeoutSeconds);
	}

	protected function isExternalEventTimeout(array $eventParams): bool
	{
		return ($eventParams['SchedulerService'] ?? null) === 'OnAgent';
	}

	protected function isExternalEventIrrelevant(
		array $eventParams,
		array $eventNames,
	): bool
	{
		if ($this->executionStatus === \CBPActivityExecutionStatus::Closed)
		{
			return true;
		}

		if ($this->eventSubscriptionHash === null)
		{
			return true;
		}

		if ($this->isExternalEventTimeout($eventParams))
		{
			return false;
		}

		$eventName = $eventParams['eventName'] ?? '';
		if (!in_array($eventName, $eventNames, true))
		{
			return true;
		}

		$eventHash = $this->extractFirstStringParam($eventParams);

		return $eventHash !== $this->eventSubscriptionHash;
	}

	protected function extractEventObject(array $eventParams, string $className): ?object
	{
		foreach ($eventParams as $key => $value)
		{
			if (is_numeric($key) && $value instanceof $className)
			{
				return $value;
			}
		}

		return null;
	}

	protected function extractEventError(array $eventParams): ?\Bitrix\Main\Error
	{
		return $this->extractEventObject($eventParams, \Bitrix\Main\Error::class);
	}

	protected function reInitializeEventSubscription(): void
	{
		$this->eventSubscriptionHash = null;
		$this->eventSubscriptionTimeoutId = 0;
	}

	private function extractFirstStringParam(array $eventParams): ?string
	{
		foreach ($eventParams as $key => $value)
		{
			if (is_numeric($key) && is_string($value))
			{
				return $value;
			}
		}

		return null;
	}
}
