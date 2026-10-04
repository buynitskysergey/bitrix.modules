<?php

namespace Bitrix\Crm\Activity\Provider\Tasks;

use Bitrix\Main\Type\DateTime;

class TaskActivityState
{
	/**
	 * @param int[]|null $completedEntryIds
	 */
	public function __construct(
		public readonly int $activityId,
		public readonly bool $completed,
		public readonly ?string $status,
		public readonly ?DateTime $endTime,
		public readonly ?array $completedEntryIds,
	)
	{
	}
}
