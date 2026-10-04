<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Activity\Structure;

trait FlowListenerTrait
{
	public function subscribeOutputFlow(int $port = 0): void
	{
		$rootNode = $this->getRootActivity();
		$names = $rootNode->getOutputNames($this->getName(), [$port]);

		foreach ($names as $activityLink)
		{
			$activityName = $rootNode->extractActivityNameFromLink($activityLink);
			$activity = $this->workflow->getActivityByName($activityName);
			if ($activity instanceof \IBPEventDrivenActivity)
			{
				$activity->addStatusChangeHandler(self::ClosedEvent, $this);
			}
		}
	}

	public function onFlowEvent(\CBPActivity $sender, int $port = 0): void
	{
		$sender->removeStatusChangeHandler(self::ClosedEvent, $this);
		$rootNode = $this->getRootActivity();
		$senderName = $sender->getName();
		$names = $rootNode->getOutputNames($this->getName(), [$port]);

		foreach ($names as $activityLink)
		{
			$activityName = $rootNode->extractActivityNameFromLink($activityLink);

			if ($activityName === $senderName)
			{
				continue;
			}

			$activity = $this->workflow->getActivityByName($activityName);
			if (
				$activity instanceof \IBPEventDrivenActivity
				&& $activity->executionStatus === \CBPActivityExecutionStatus::Executing
			)
			{
				$sender->removeStatusChangeHandler(self::ClosedEvent, $this);
				$this->workflow->cancelActivity($activity);
			}
		}
	}
}
