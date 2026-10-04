<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\User\Vibecode;

use Bitrix\Main\Result;
use Bitrix\Vibecodeconnector\Internal\Entity\User\UserEventChange;
use Bitrix\Vibecodeconnector\Internal\Service\Vibecode\AbstractMicroserviceClient;

final class UserEventClient extends AbstractMicroserviceClient
{
	private const ACTION = 'user.event';
	private const OCCURRED_AT_FORMAT = 'Y-m-d\TH:i:s\Z';

	public function sendEvent(
		int $bitrixUserId,
		UserEventChange $change,
		int $occurredAt,
		?bool $isAdmin = null,
		?bool $isIntegrator = null,
		?int $groupEventSequence = null,
	): Result
	{
		$this->validateGroupFields($change, $isAdmin, $isIntegrator, $groupEventSequence);

		$event = [
			'bitrix_user_id' => $bitrixUserId,
			'change' => $change->value,
			'occurred_at' => gmdate(self::OCCURRED_AT_FORMAT, $occurredAt),
		];
		if ($change === UserEventChange::GroupsChanged)
		{
			$event['is_admin'] = $isAdmin;
			$event['is_integrator'] = $isIntegrator;
			$event['group_event_sequence'] = $groupEventSequence;
		}

		return $this->performRequest(self::ACTION, [
			'events' => [$event],
		]);
	}

	private function validateGroupFields(
		UserEventChange $change,
		?bool $isAdmin,
		?bool $isIntegrator,
		?int $groupEventSequence,
	): void {
		if ($change === UserEventChange::GroupsChanged)
		{
			if (
				$isAdmin === null
				|| $isIntegrator === null
				|| $groupEventSequence === null
				|| $groupEventSequence <= 0
			)
			{
				throw new \InvalidArgumentException(
					'Groups changed event requires admin flag, integrator flag and positive group event sequence',
				);
			}

			return;
		}

		if ($isAdmin !== null || $isIntegrator !== null || $groupEventSequence !== null)
		{
			throw new \InvalidArgumentException('Group event fields are only allowed for groups changed event');
		}
	}
}
