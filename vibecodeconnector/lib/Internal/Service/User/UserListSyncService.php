<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\User;

use Bitrix\Vibecodeconnector\Internal\Repository\User\UserRepository;

final class UserListSyncService
{
	public const MAX_USER_IDS = 100_000;

	private const MAX_USER_ID = 2147483647;

	public function __construct(
		private readonly UserRepository $repository = new UserRepository(),
	) {
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function sync(array $data): void
	{
		if (!isset($data['ids']) || !is_array($data['ids']))
		{
			throw new \UnexpectedValueException('Invalid user.list payload');
		}
		if (count($data['ids']) > self::MAX_USER_IDS)
		{
			throw new \UnexpectedValueException('Invalid user.list payload');
		}

		$normalized = [];
		foreach ($data['ids'] as $rawId)
		{
			if (!is_int($rawId) || $rawId < 1 || $rawId > self::MAX_USER_ID)
			{
				throw new \UnexpectedValueException('Invalid user.list payload');
			}

			$normalized[$rawId] = $rawId;
		}

		$this->repository->addMissing($normalized);
	}
}
