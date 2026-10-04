<?php

namespace Bitrix\HumanResources\Service\HcmLink;

use Bitrix\HumanResources\Item\HcmLink\Employee;
use Bitrix\HumanResources\Service\Container;
use Closure;

/**
 * Delivers salary and vacation API results to an employee.
 *
 * 1C returns the PIN through field.value.set in the field identified by self::FIELD_CODE.
 * Pull notifications are sent to Person.userId. Polling through getResult remains available
 * when Pull is unavailable or the employee is not mapped.
 */
class PinService
{
	/** HCM Link field code used by 1C to return the PIN. */
	public const FIELD_CODE = 'PIN';

	/** Pull command emitted when the PIN is ready. */
	public const PUSH_COMMAND_PIN_READY = 'salaryVacationPinReady';

	/** Pull command emitted when the document is ready. */
	public const PUSH_COMMAND_DOCUMENT_READY = 'salaryVacationDocumentReady';

	private readonly Closure $send;

	public function __construct(?Closure $send = null)
	{
		$this->send = $send ?? $this->sendMessage(...);
	}

	public function deliver(Employee $employee, string $pin, ?int $taskId = null): void
	{
		$userId = $this->resolveUserId($employee);
		if ($userId === null)
		{
			return;
		}

		($this->send)(
			self::PUSH_COMMAND_PIN_READY,
			[
				'taskId' => $taskId,
				'pin' => $pin,
			],
			[$userId],
		);
	}

	public function deliverDocument(Employee $employee, int $taskId): void
	{
		$userId = $this->resolveUserId($employee);
		if ($userId === null)
		{
			return;
		}

		($this->send)(
			self::PUSH_COMMAND_DOCUMENT_READY,
			[
				'taskId' => $taskId,
			],
			[$userId],
		);
	}

	private function resolveUserId(Employee $employee): ?int
	{
		$userId = Container::getHcmLinkPersonRepository()->getById($employee->personId)?->userId;

		return ($userId !== null && $userId > 0) ? $userId : null;
	}

	private function sendMessage(string $command, array $params, array $userIds): bool
	{
		return Container::getPushMessageService()->send($command, $params, $userIds);
	}
}
