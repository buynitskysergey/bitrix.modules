<?php

declare(strict_types=1);

namespace Bitrix\Crm\Activity\Email\Read;

use Bitrix\Crm\Activity\Email\Access;
use Bitrix\Crm\Activity\Provider\Email;
use Bitrix\Crm\ActivityTable;
use Bitrix\Crm\Result;

final class ContentProvider
{
	public function __construct(
		private readonly Access $access = new Access(),
	)
	{
	}

	public function get(int $activityId, int $userId): Result
	{
		if ($activityId <= 0)
		{
			return Result::fail('activityId must be a positive integer.');
		}

		$row =
			ActivityTable::query()
				->setSelect([
					'ID',
					'SUBJECT',
					'START_TIME',
					'CREATED',
					'LAST_UPDATED',
					'DEADLINE',
					'DIRECTION',
					'OWNER_TYPE_ID',
					'OWNER_ID',
					'PROVIDER_ID',
					'PROVIDER_TYPE_ID',
					'ASSOCIATED_ENTITY_ID',
					'PARENT_ID',
					'THREAD_ID',
					'AUTHOR_ID',
					'EDITOR_ID',
					'RESPONSIBLE_ID',
					'DESCRIPTION',
					'DESCRIPTION_TYPE',
					'SETTINGS',
				])
				->where('ID', $activityId)
				->where('PROVIDER_ID', Email::getId())
				->fetch()
		;

		if (!$row)
		{
			return Result::fail('CRM email activity not found.');
		}

		$bindings = $this->access->fetchBindings($activityId);
		if (!$this->access->canRead($row, $userId, $bindings, checkOwnerFirst: false))
		{
			return Result::fail('Access denied to the CRM email activity.');
		}

		Email::uncompressActivityDescription($row);

		return Result::success(
			activity: EmailActivity::fromActivityRow($row),
			bindings: $this->access->formatBindings($bindings),
		);
	}
}
