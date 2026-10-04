<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Repository\User;

use Bitrix\Vibecodeconnector\Internal\Model\User\UserTable;

class UserRepository
{
	private const INSERT_BATCH_SIZE = 500;

	public function contains(int $bitrixUserId): bool
	{
		return UserTable::query()
			->setSelect(['ID'])
			->where('BITRIX_USER_ID', $bitrixUserId)
			->setLimit(1)
			->fetch() !== false
		;
	}

	/**
	 * @param iterable<int> $bitrixUserIds
	 */
	public function addMissing(iterable $bitrixUserIds): void
	{
		$batch = [];
		foreach ($bitrixUserIds as $bitrixUserId)
		{
			$batch[] = ['BITRIX_USER_ID' => $bitrixUserId];
			if (count($batch) === self::INSERT_BATCH_SIZE)
			{
				$this->insertBatch($batch);
				$batch = [];
			}
		}

		if ($batch !== [])
		{
			$this->insertBatch($batch);
		}
	}

	/**
	 * @param list<array{BITRIX_USER_ID: int}> $batch
	 */
	protected function insertBatch(array $batch): void
	{
		UserTable::addInsertIgnoreMulti($batch, true);
	}
}
