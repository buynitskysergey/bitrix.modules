<?php

declare(strict_types=1);

namespace Bitrix\Crm\Activity\Email\Read;

use Bitrix\Crm\Activity\Email\Access;
use Bitrix\Crm\Activity\Provider\Email;
use Bitrix\Crm\ActivityTable;
use Bitrix\Crm\Result;

final class ThreadProvider
{
	public const MAX_THREAD = 200;

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

		$root =
			ActivityTable::query()
				->setSelect(['ID', 'THREAD_ID', 'OWNER_TYPE_ID', 'OWNER_ID'])
				->where('ID', $activityId)
				->where('PROVIDER_ID', Email::getId())
				->fetch()
		;

		if (!$root)
		{
			return Result::fail('CRM email activity not found.');
		}

		$rootBindings = $this->access->fetchBindings($activityId);
		if (!$this->access->canRead($root, $userId, $rootBindings, checkOwnerFirst: false))
		{
			return Result::fail('Access denied to the CRM email activity.');
		}

		$threadId = (int)$root['THREAD_ID'];
		if ($threadId <= 0)
		{
			$single =
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
						'SETTINGS',
					])
					->where('ID', $activityId)
					->fetch()
			;

			return Result::success(messages: [ThreadEntry::visible(EmailActivity::fromActivityRow($single))], truncated: false);
		}

		$result =
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
					'SETTINGS',
				])
				->where('THREAD_ID', $threadId)
				->where('PROVIDER_ID', Email::getId())
				->setOrder(['START_TIME' => 'ASC'])
				->setLimit(self::MAX_THREAD + 1)
				->exec()
		;

		$truncated = false;
		$rows = $result->fetchAll();
		if (count($rows) > self::MAX_THREAD)
		{
			$truncated = true;
			$rows = array_slice($rows, 0, self::MAX_THREAD);
		}

		$bindingsByActivityId = $this->access->fetchBindingsByActivityId(
			array_map(static fn(array $row): int => (int)$row['ID'], $rows),
		);

		$messages = [];
		foreach ($rows as $row)
		{
			$id = (int)$row['ID'];
			if ($this->access->canRead($row, $userId, $bindingsByActivityId[$id] ?? [], checkOwnerFirst: false))
			{
				$messages[] = ThreadEntry::visible(EmailActivity::fromActivityRow($row));
			}
			else
			{
				$messages[] = ThreadEntry::hidden();
			}
		}

		return Result::success(messages: $messages, truncated: $truncated);
	}
}
