<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\Bizproc;

use Bitrix\Bizproc\Starter\Dto\ContextDto;
use Bitrix\Bizproc\Starter\Enum\Scenario;
use Bitrix\Bizproc\Starter\Starter;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

/**
 * Not final on purpose: the absence of the process engine cannot be arranged within one process, so it is
 * read through an overridable method.
 */
class ProcessLauncher implements ProcessLauncherInterface
{
	private const CONTEXT_MODULE_ID = 'humanresources';

	/**
	 * Must match `setClass()` of the node descriptor and the designer catalog layout: a mismatch gives a
	 * node that is either invisible or never started, without an error.
	 */
	private const TRIGGER_CODE = 'NewEmployeeTrigger';

	/**
	 * A passed delay, whatever its value, is what moves the start out of the current request: the engine
	 * switches to the scheduled path on the value being present at all.
	 */
	private const START_DELAY = 0;

	/**
	 * The engine is guarded here and not left to the caller: the launcher is a registered service, so any
	 * module may reach it on a portal where bizproc is absent.
	 */
	public function launchForNewEmployee(int $userId): Result
	{
		if ($userId <= 0)
		{
			return (new Result())->addError(
				new Error('Employee id is required.', 'HUMANRESOURCES_BIZPROC_LAUNCH_EMPLOYEE_ID_REQUIRED'),
			);
		}

		if (!$this->includeProcessEngine())
		{
			return (new Result())->addError(
				new Error('The process engine is not available.', 'HUMANRESOURCES_BIZPROC_LAUNCH_ENGINE_UNAVAILABLE'),
			);
		}

		return Starter::getByScenario(Scenario::onEvent)
			->setContext(new ContextDto(self::CONTEXT_MODULE_ID))
			->setDelay(self::START_DELAY)
			->addEvent(self::TRIGGER_CODE, [], $this->buildEventFields($userId))
			->start()
		;
	}

	protected function includeProcessEngine(): bool
	{
		return Loader::includeModule('bizproc');
	}

	/**
	 * @return array<string, mixed>
	 */
	private function buildEventFields(int $userId): array
	{
		return [
			NewEmployeeTrigger::FIELD_USER_ID => $userId,
			NewEmployeeTrigger::FIELD_EVENT_DATE_TIME => (new DateTime())->format(DateTime::getFormat()),
		];
	}
}
