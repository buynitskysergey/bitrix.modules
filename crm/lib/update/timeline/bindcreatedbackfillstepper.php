<?php

namespace Bitrix\Crm\Update\Timeline;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Timeline\Entity\TimelineBindingTable;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Update\Stepper;

final class BindCreatedBackfillStepper extends Stepper
{
	private const PHASE_BACKFILL = 'backfill';
	private const PHASE_ORPHANS = 'orphans';

	protected static $moduleId = 'crm';

	public function execute(array &$option): bool
	{
		return false; // temporary disabled
		$lastOwner = isset($option['lastOwner']) ? (int)$option['lastOwner'] : 0;
		$lastEntity = isset($option['lastEntity']) ? (int)$option['lastEntity'] : 0;
		$lastType = isset($option['lastType']) ? (int)$option['lastType'] : 0;
		$lastOrphanOwner = isset($option['lastOrphanOwner']) ? (int)$option['lastOrphanOwner'] : 0;
		$phase = isset($option['phase']) ? (string)$option['phase'] : self::PHASE_BACKFILL;

		$portionLimit = (int)Option::get(
			'crm',
			'timeline_bind_created_backfill_step_limit',
			100
		);
		$timeLimitSeconds = (float)Option::get(
			'crm',
			'timeline_bind_created_backfill_time_limit',
			1
		);

		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();
		$startTime = microtime(true);

		do
		{
			if ($phase === self::PHASE_ORPHANS)
			{
				$orphanOwners = $this->selectOrphanOwners(
					$connection, $helper, $lastOrphanOwner, $portionLimit
				);

				if (empty($orphanOwners))
				{
					return $this->finishBackfill($connection, $helper);
				}

				$this->deleteOrphanBinds($connection, $helper, $orphanOwners);

				$lastOrphanOwner = end($orphanOwners);
				$option['lastOrphanOwner'] = $lastOrphanOwner;

				continue;
			}

			$rows = $this->selectPortion(
				$connection, $helper, $lastOwner, $lastEntity, $lastType, $portionLimit
			);

			if (empty($rows))
			{
				$phase = self::PHASE_ORPHANS;
				$option['phase'] = $phase;

				continue;
			}

			$this->fillPortion($connection, $helper, $rows);

			$lastRow = end($rows);
			$lastOwner = $lastRow['OWNER_ID'];
			$lastEntity = $lastRow['ENTITY_ID'];
			$lastType = $lastRow['ENTITY_TYPE_ID'];
			$option['lastOwner'] = $lastOwner;
			$option['lastEntity'] = $lastEntity;
			$option['lastType'] = $lastType;
		}
		while (microtime(true) - $startTime < $timeLimitSeconds);

		return self::CONTINUE_EXECUTION;
	}

	private function finishBackfill(
		\Bitrix\Main\DB\Connection $connection,
		\Bitrix\Main\DB\SqlHelper $helper
	): bool
	{
		$logger = Container::getInstance()->getLogger('Agent');
		$nullCount = $this->getNullCount($connection, $helper);
		if ($nullCount === 0)
		{
			Option::delete('crm', ['name' => 'timeline_bind_created_enabled']);
			$logger->info(
				'BindCreatedBackfillStepper: backfill completed successfully,'
				. ' transitional option removed, optimized read-path enabled'
			);
		}
		else
		{
			$logger->warning(
				'BindCreatedBackfillStepper: backfill completed but some bind rows'
				. ' still have NULL CREATED, transitional option kept as N,'
				. ' read-path stays on legacy',
				['nullCount' => $nullCount]
			);
		}

		return self::FINISH_EXECUTION;
	}

	/**
	 * @return array<int, array{OWNER_ID: int, ENTITY_ID: int, ENTITY_TYPE_ID: int}>
	 */
	private function selectPortion(
		\Bitrix\Main\DB\Connection $connection,
		\Bitrix\Main\DB\SqlHelper $helper,
		int $lastOwner,
		int $lastEntity,
		int $lastType,
		int $portionLimit
	): array
	{
		$bindTable = $helper->quote('b_crm_timeline_bind');

		$sql =
			'SELECT '
			. $helper->quote('OWNER_ID') . ', '
			. $helper->quote('ENTITY_ID') . ', '
			. $helper->quote('ENTITY_TYPE_ID')
			. ' FROM ' . $bindTable
			. ' WHERE ' . $helper->quote('CREATED') . ' IS NULL'
			. ' AND ('
				. '(' . $helper->quote('OWNER_ID') . ' > ' . $lastOwner . ')'
				. ' OR (' . $helper->quote('OWNER_ID') . ' = ' . $lastOwner
					. ' AND ' . $helper->quote('ENTITY_ID') . ' > ' . $lastEntity . ')'
				. ' OR (' . $helper->quote('OWNER_ID') . ' = ' . $lastOwner
					. ' AND ' . $helper->quote('ENTITY_ID') . ' = ' . $lastEntity
					. ' AND ' . $helper->quote('ENTITY_TYPE_ID') . ' > ' . $lastType . ')'
			. ')'
			. ' ORDER BY '
				. $helper->quote('OWNER_ID') . ', '
				. $helper->quote('ENTITY_ID') . ', '
				. $helper->quote('ENTITY_TYPE_ID')
			. ' LIMIT ' . $portionLimit
		;

		$result = $connection->query($sql);
		$rows = [];
		while ($row = $result->fetch())
		{
			$rows[] = [
				'OWNER_ID' => (int)$row['OWNER_ID'],
				'ENTITY_ID' => (int)$row['ENTITY_ID'],
				'ENTITY_TYPE_ID' => (int)$row['ENTITY_TYPE_ID'],
			];
		}

		return $rows;
	}

	/**
	 * Fills CREATED for all NULL binds of the portion owners with a single
	 * batch UPDATE. The value depends on the owner only, and the CREATED IS
	 * NULL guard keeps the operation idempotent, so entity pair conditions
	 * are not needed: extra NULL binds of the last portion owner get the same
	 * correct value and are simply not selected by the next portion.
	 */
	private function fillPortion(
		\Bitrix\Main\DB\Connection $connection,
		\Bitrix\Main\DB\SqlHelper $helper,
		array $rows
	): void
	{
		$ownerIds = [];
		foreach ($rows as $row)
		{
			$ownerIds[$row['OWNER_ID']] = true;
		}
		$ownerList = implode(', ', array_keys($ownerIds));

		$bindTable = $helper->quote('b_crm_timeline_bind');
		$mainTable = $helper->quote('b_crm_timeline');
		$created = $helper->quote('CREATED');

		if ($connection->getType() === 'pgsql')
		{
			$sql =
				'UPDATE ' . $bindTable . ' bind'
				. ' SET ' . $created . ' = main.' . $created
				. ' FROM ' . $mainTable . ' main'
				. ' WHERE main.' . $helper->quote('ID') . ' = bind.' . $helper->quote('OWNER_ID')
				. ' AND bind.' . $created . ' IS NULL'
				. ' AND bind.' . $helper->quote('OWNER_ID') . ' IN (' . $ownerList . ')'
			;
		}
		else
		{
			$sql =
				'UPDATE ' . $bindTable . ' bind'
				. ' INNER JOIN ' . $mainTable . ' main'
					. ' ON main.' . $helper->quote('ID') . ' = bind.' . $helper->quote('OWNER_ID')
				. ' SET bind.' . $created . ' = main.' . $created
				. ' WHERE bind.' . $created . ' IS NULL'
				. ' AND bind.' . $helper->quote('OWNER_ID') . ' IN (' . $ownerList . ')'
			;
		}

		$connection->queryExecute($sql);

		TimelineBindingTable::cleanCache();
	}

	/**
	 * Selects the next portion of bind owners missing in b_crm_timeline.
	 * The anti-join is read-only, so it takes no write locks; the actual
	 * delete is done separately by the collected keys.
	 *
	 * @return int[]
	 */
	private function selectOrphanOwners(
		\Bitrix\Main\DB\Connection $connection,
		\Bitrix\Main\DB\SqlHelper $helper,
		int $lastOrphanOwner,
		int $portionLimit
	): array
	{
		$bindTable = $helper->quote('b_crm_timeline_bind');
		$mainTable = $helper->quote('b_crm_timeline');

		$sql =
			'SELECT DISTINCT bind.' . $helper->quote('OWNER_ID') . ' AS ' . $helper->quote('OWNER_ID')
			. ' FROM ' . $bindTable . ' bind'
			. ' LEFT JOIN ' . $mainTable . ' main'
				. ' ON main.' . $helper->quote('ID') . ' = bind.' . $helper->quote('OWNER_ID')
			. ' WHERE main.' . $helper->quote('ID') . ' IS NULL'
			. ' AND bind.' . $helper->quote('OWNER_ID') . ' > ' . $lastOrphanOwner
			. ' ORDER BY bind.' . $helper->quote('OWNER_ID')
			. ' LIMIT ' . $portionLimit
		;

		$result = $connection->query($sql);
		$owners = [];
		while ($row = $result->fetch())
		{
			$owners[] = (int)$row['OWNER_ID'];
		}

		return $owners;
	}

	/**
	 * An owner missing in b_crm_timeline has only orphan binds, so the
	 * portion is deleted by keys without a join; the lock is short and
	 * touches the deleted rows only.
	 *
	 * @param int[] $ownerIds
	 */
	private function deleteOrphanBinds(
		\Bitrix\Main\DB\Connection $connection,
		\Bitrix\Main\DB\SqlHelper $helper,
		array $ownerIds
	): void
	{
		$sql =
			'DELETE FROM ' . $helper->quote('b_crm_timeline_bind')
			. ' WHERE ' . $helper->quote('OWNER_ID') . ' IN (' . implode(', ', $ownerIds) . ')'
		;

		$connection->queryExecute($sql);

		TimelineBindingTable::cleanCache();
	}

	private function getNullCount(
		\Bitrix\Main\DB\Connection $connection,
		\Bitrix\Main\DB\SqlHelper $helper
	): int
	{
		$bindTable = $helper->quote('b_crm_timeline_bind');

		$sql =
			'SELECT COUNT(*) AS CNT FROM ' . $bindTable
			. ' WHERE ' . $helper->quote('CREATED') . ' IS NULL'
		;

		$row = $connection->query($sql)->fetch();

		return $row ? (int)$row['CNT'] : 0;
	}

	public static function bindOnCrmModuleInstall(): void
	{
		\CAgent::AddAgent(
			/** @see self::execAgent() */
			self::class . '::execAgent();',
			'crm',
			'Y',
			60,
			'',
			'Y',
			\ConvertTimeStamp(time() + \CTimeZone::GetOffset() + 300, 'FULL'),
			100,
			false,
			false
		);
	}
}
