<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\User;

use Bitrix\Main\Loader;
use Bitrix\Main\UserTable;
use Bitrix\Vibecodeconnector\Internal\Entity\User\UserAttribute;
use Bitrix\Vibecodeconnector\Internal\Repository\User\UserAttributesRepository;

class UserAttributesResolver
{
	private ?int $integratorGroupId = null;

	public function __construct(
		private readonly UserAttributesRepository $userAttributesRepository = new UserAttributesRepository(),
	) {
	}

	/**
	 * @return array{isAdmin: bool, isIntegrator: bool}
	 */
	public function resolveForUser(int $userId): array
	{
		$groups = $this->getCurrentGroupIds($userId);

		return [
			'isAdmin' => $this->containsAdminGroup($groups),
			'isIntegrator' => $this->containsIntegratorGroup($groups),
		];
	}

	/**
	 * @param list<int> $userIds
	 * @return list<array{userId: int, isAdmin: bool, isIntegrator: bool, groupEventSequence: int}>
	 */
	public function getAttributesForUsers(array $userIds): array
	{
		$sequences = $this->getGroupEventSequences($userIds);
		$existingUserIds = array_flip($this->filterExistingUsers($userIds));

		$result = [];
		foreach ($userIds as $userId)
		{
			if (!isset($existingUserIds[$userId]))
			{
				continue;
			}

			$attributes = $this->resolveForUser($userId);

			$result[] = [
				'userId' => $userId,
				'isAdmin' => $attributes['isAdmin'],
				'isIntegrator' => $attributes['isIntegrator'],
				'groupEventSequence' => $sequences[$userId] ?? 0,
			];
		}

		return $result;
	}

	/**
	 * @param list<int> $userIds
	 * @return array<int, int>
	 */
	protected function getGroupEventSequences(array $userIds): array
	{
		return array_map(
			static fn(mixed $value): int => (int)$value,
			$this->userAttributesRepository->getMany($userIds, UserAttribute::GroupEventSequence),
		);
	}

	/**
	 * @param list<int> $userIds
	 * @return list<int>
	 */
	protected function filterExistingUsers(array $userIds): array
	{
		if ($userIds === [])
		{
			return [];
		}

		$rows = UserTable::query()
			->setSelect(['ID'])
			->whereIn('ID', $userIds)
			->fetchAll()
		;

		return array_map(static fn(array $row): int => (int)$row['ID'], $rows);
	}

	protected function getCurrentGroupIds(int $userId): array
	{
		return \CUser::GetUserGroup($userId);
	}

	protected function getIntegratorGroupId(): int
	{
		if ($this->integratorGroupId === null)
		{
			$this->integratorGroupId = $this->resolveIntegratorGroupId();
		}

		return $this->integratorGroupId;
	}

	protected function resolveIntegratorGroupId(): int
	{
		return Loader::includeModule('bitrix24')
			? (int)\Bitrix\Bitrix24\Integrator::getIntegratorGroupId()
			: 0;
	}

	protected function containsAdminGroup(mixed $groups): bool
	{
		if (!is_array($groups))
		{
			return false;
		}

		foreach ($groups as $group)
		{
			$groupId = is_array($group) ? ($group['GROUP_ID'] ?? null) : $group;
			if ((int)$groupId === 1)
			{
				return true;
			}
		}

		return false;
	}

	protected function containsIntegratorGroup(mixed $groups): bool
	{
		if (!is_array($groups))
		{
			return false;
		}

		$integratorGroupId = $this->getIntegratorGroupId();
		if ($integratorGroupId <= 0)
		{
			return false;
		}

		foreach ($groups as $group)
		{
			$groupId = is_array($group) ? ($group['GROUP_ID'] ?? null) : $group;
			if ((int)$groupId === $integratorGroupId)
			{
				return true;
			}
		}

		return false;
	}
}
