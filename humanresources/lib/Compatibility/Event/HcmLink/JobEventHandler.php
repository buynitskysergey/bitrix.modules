<?php

namespace Bitrix\HumanResources\Compatibility\Event\HcmLink;

use Bitrix\HumanResources\Type\HcmLink\JobType;
use Bitrix\Main\Event;

class JobEventHandler
{
	public static function onUpdateDoneJob(Event $event): void
	{
		/** @var \Bitrix\HumanResources\Item\HcmLink\Job $job */
		$job = $event->getParameter('job');

		// PIN and salary/vacation requests are closed by the same job-done event but are
		// unrelated to employee mapping synchronization, so the PULL
		// external_employee_list_updated command must not be sent for them.
		if ($job->type === JobType::PIN_REQUEST || $job->type === JobType::SALARY_VACATION_REQUEST)
		{
			return;
		}

		\Bitrix\Main\Loader::includeModule('pull');

		\CPullWatch::AddToStack('humanresources_person_mapping', [
			'module_id' => 'humanresources',
			'command' => 'external_employee_list_updated',
			'params' => [
				'jobId' => $job->id,
				'status' => $job->status->value,
				'finishedAt' => $job->finishedAt
			],
		]);
	}
}
