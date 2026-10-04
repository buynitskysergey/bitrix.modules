<?php

namespace Bitrix\Mobile\Internal\Onboarding\Services;

use Bitrix\Main\UserTable;

class UserSelectorService
{
	private const FILTER_CHUNK_SIZE = 300;

	private MobileActivityService $activityService;

	public function __construct(?MobileActivityService $activityService = null)
	{
		$this->activityService = $activityService ?? new MobileActivityService();
	}

	public function isValidUser(int $userId): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		$user = UserTable::getList([
			'select' => ['ID'],
			'filter' => array_merge(['=ID' => $userId], $this->getValidUserFilter()),
			'limit' => 1,
		])->fetch();

		return (bool)$user;
	}

	public function getValidMobileUsers(): array
	{
		return $this->filterValidUserIds($this->activityService->getUserIdsWithActivity());
	}

	/**
	 * @param int[] $userIds
	 * @return int[]
	 */
	public function filterValidUserIds(array $userIds): array
	{
		$userIds = array_unique(array_map('intval', $userIds));
		$userIds = array_filter($userIds, static fn (int $id): bool => $id > 0);
		if (empty($userIds))
		{
			return [];
		}

		$validIds = [];
		foreach (array_chunk($userIds, self::FILTER_CHUNK_SIZE) as $chunk)
		{
			array_push($validIds, ...$this->queryValidUserIds($chunk));
		}

		return $validIds;
	}

	/**
	 * @param int[] $chunk
	 * @return int[]
	 */
	protected function queryValidUserIds(array $chunk): array
	{
		$users = UserTable::getList([
			'select' => ['ID'],
			'filter' => array_merge(['=ID' => $chunk], $this->getValidUserFilter()),
		])->fetchAll();

		return array_map(static fn (array $user): int => (int)$user['ID'], $users);
	}

	private function getValidUserFilter(): array
	{
		return [
			'=ACTIVE' => 'Y',
			'=CONFIRM_CODE' => false,
			'!=UF_DEPARTMENT' => false,
			'!=EXTERNAL_AUTH_ID' => UserTable::getExternalUserTypes(),
		];
	}
}
