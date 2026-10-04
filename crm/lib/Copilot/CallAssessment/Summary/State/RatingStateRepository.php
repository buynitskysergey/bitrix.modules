<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\CallAssessment\Summary\State;

use Bitrix\Crm\Copilot\CallAssessment\Summary\Entity\SummaryStateTable;
use Bitrix\Crm\Copilot\CallAssessment\Summary\Situation;
use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;

final class RatingStateRepository
{
	public const STATE_NOTIFIED = 'NOTIFIED';
	public const STATE_CLEARED = 'CLEARED';

	/**
	 * @return array{notified: bool, lastNotifiedAt: ?DateTime}
	 */
	public function fetchStatus(int $managerId, Situation $situation): array
	{
		$default = [
			'notified' => false,
			'lastNotifiedAt' => null,
		];

		if ($managerId <= 0)
		{
			return $default;
		}

		$row = SummaryStateTable::query()
			->setSelect(['STATE', 'NOTIFIED_AT'])
			->where('MANAGER_ID', $managerId)
			->where('SITUATION_TYPE', $situation->value)
			->setLimit(1)
			->fetch()
		;

		if (!is_array($row))
		{
			return $default;
		}

		return [
			'notified' => ($row['STATE'] ?? null) === self::STATE_NOTIFIED,
			'lastNotifiedAt' => $row['NOTIFIED_AT'] instanceof DateTime ? $row['NOTIFIED_AT'] : null,
		];
	}

	public function isNotified(int $managerId, Situation $situation): bool
	{
		return $this->fetchStatus($managerId, $situation)['notified'];
	}

	public function markNotified(int $managerId, Situation $situation): void
	{
		if ($managerId <= 0)
		{
			return;
		}

		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();

		$now = new DateTime();

		$sql = $helper->prepareMerge(
			SummaryStateTable::getTableName(),
			[
				'MANAGER_ID',
				'SITUATION_TYPE',
			],
			[
				'MANAGER_ID' => $managerId,
				'SITUATION_TYPE' => $situation->value,
				'STATE' => self::STATE_NOTIFIED,
				'NOTIFIED_AT' => $now,
			],
			[
				'STATE' => self::STATE_NOTIFIED,
				'NOTIFIED_AT' => $now,
			],
		);

		$statement = is_array($sql) ? current($sql) : $sql;
		if (is_string($statement) && $statement !== '')
		{
			$connection->queryExecute($statement);
			SummaryStateTable::cleanCache();
		}
	}

	public function clearNotification(int $managerId, Situation $situation): void
	{
		if ($managerId <= 0)
		{
			return;
		}

		$row = SummaryStateTable::query()
			->setSelect(['ID'])
			->where('MANAGER_ID', $managerId)
			->where('SITUATION_TYPE', $situation->value)
			->where('STATE', self::STATE_NOTIFIED)
			->setLimit(1)
			->fetch()
		;

		if (!is_array($row))
		{
			return;
		}

		SummaryStateTable::update(
			$row['ID'],
			[
				'STATE' => self::STATE_CLEARED,
			],
		);
	}
}
