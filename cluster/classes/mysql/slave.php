<?php

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;

IncludeModuleLangFile(__FILE__);

class CClusterSlave
{
	public static function SetOnLine($node_id, $master_id)
	{
		global $DB;

		$arNode = CClusterDBNode::GetByID($node_id);
		if (!is_array($arNode))
		{
			return;
		}

		if ($arNode['ROLE_ID'] == 'SLAVE')
		{
			if ($master_id == 1)
			{
				$masterDB = $DB;
			}
			else
			{
				ob_start();
				$masterDB = CDatabase::GetDBNodeConnection($master_id, true);
				ob_end_clean();
			}

			$rs = $masterDB->Query('show master status', false, '', ['fixed_connection' => true]);
			if ($arMasterStatus = $rs->Fetch())
			{
				ob_start();
				$nodeDB = CDatabase::GetDBNodeConnection($arNode['ID'], true);
				ob_end_clean();
				if (is_object($nodeDB))
				{
					$rs = $nodeDB->Query("
						CHANGE MASTER TO
							MASTER_HOST = '" . $DB->ForSql($arNode['MASTER_HOST']) . "'
							,MASTER_USER = '" . $DB->ForSql($masterDB->DBLogin) . "'
							,MASTER_PASSWORD = '" . $DB->ForSql($masterDB->DBPassword) . "'
							,MASTER_PORT = " . $DB->ForSql($arNode['MASTER_PORT']) . "
							,MASTER_LOG_FILE = '" . $arMasterStatus['File'] . "'
							,MASTER_LOG_POS = " . $arMasterStatus['Position'] . '
					', false, '', ['fixed_connection' => true]);

					if ($rs)
					{
						$rs = $nodeDB->Query('START SLAVE');
					}

					if ($rs)
					{
						$obNode = new CClusterDBNode;
						$obNode->Update($node_id, ['MASTER_ID' => $master_id]);

						CClusterDBNode::SetOnline($node_id);
						CClusterSlave::AdjustServerID($arNode, $nodeDB);
					}
				}
			}
		}
		elseif ($arNode['ROLE_ID'] == 'MASTER' && preg_match('/^(.+):(\\d+)$/', $arNode['DB_HOST'], $match))
		{
			$rs = $DB->Query('show master status', false, '', ['fixed_connection' => true]);
			if ($arMasterStatus = $rs->Fetch())
			{
				ob_start();
				$nodeDB = CDatabase::GetDBNodeConnection($arNode['ID'], true);
				ob_end_clean();
				if (is_object($nodeDB))
				{
					$nodeDB->Query('STOP SLAVE', false, '', ['fixed_connection' => true]);

					$nodeDB->Query("
						CHANGE MASTER TO
							MASTER_HOST = '" . $DB->ForSql($arNode['MASTER_HOST']) . "'
							,MASTER_USER = '" . $DB->ForSql($DB->DBLogin) . "'
							,MASTER_PASSWORD = '" . $DB->ForSql($DB->DBPassword) . "'
							,MASTER_PORT = " . $DB->ForSql($arNode['MASTER_PORT']) . "
							,MASTER_LOG_FILE = '" . $arMasterStatus['File'] . "'
							,MASTER_LOG_POS = " . $arMasterStatus['Position'] . '
					', false, '', ['fixed_connection' => true]);

					$nodeDB->Query('START SLAVE', false, '', ['fixed_connection' => true]);

					$rs = $nodeDB->Query('show master status', false, '', ['fixed_connection' => true]);
					if ($arMasterStatus = $rs->Fetch())
					{
						$rs = $DB->Query('STOP SLAVE', true, '', ['fixed_connection' => true]);

						if ($rs)
						{
							$rs = $DB->Query("
								CHANGE MASTER TO
									MASTER_HOST = '" . $DB->ForSql($match[1]) . "'
									,MASTER_USER = '" . $DB->ForSql($arNode['DB_LOGIN']) . "'
									,MASTER_PASSWORD = '" . $DB->ForSql($arNode['DB_PASSWORD']) . "'
									,MASTER_PORT = " . $DB->ForSql($match[2]) . "
									,MASTER_LOG_FILE = '" . $arMasterStatus['File'] . "'
									,MASTER_LOG_POS = " . $arMasterStatus['Position'] . '
							', false, '', ['fixed_connection' => true]);
						}

						if ($rs)
						{
							$rs = $DB->Query('START SLAVE');
						}

						if ($rs)
						{
							$obNode = new CClusterDBNode;
							$obNode->Update($node_id, ['MASTER_ID' => $master_id]);
							$obNode->Update($master_id, ['MASTER_ID' => $node_id]);

							CClusterDBNode::SetOnline($node_id);
							CClusterSlave::AdjustServerID($arNode, $nodeDB);
						}
					}
				}
			}
		}
	}

	public static function Pause($node_id)
	{
		global $DB;

		$arNode = CClusterDBNode::GetByID($node_id);
		if (!is_array($arNode))
		{
			return;
		}

		if ($node_id == 1)
		{
			$nodeDB = $DB;
		}
		else
		{
			ob_start();
			$nodeDB = CDatabase::GetDBNodeConnection($arNode['ID'], true);
			ob_end_clean();
		}

		if (!is_object($nodeDB))
		{
			return;
		}

		$rs = $nodeDB->Query('STOP SLAVE SQL_THREAD', false, '', ['fixed_connection' => true]);
		if ($rs)
		{
			$ob = new CClusterDBNode;
			$ob->Update($arNode['ID'], ['STATUS' => 'PAUSED']);
		}
	}

	public static function Resume($node_id)
	{
		global $DB;

		$arNode = CClusterDBNode::GetByID($node_id);
		if (!is_array($arNode))
		{
			return;
		}

		if ($node_id == 1)
		{
			$nodeDB = $DB;
		}
		else
		{
			ob_start();
			$nodeDB = CDatabase::GetDBNodeConnection($arNode['ID'], true, false);
			ob_end_clean();
		}

		if (!is_object($nodeDB))
		{
			return;
		}

		$rs = $nodeDB->Query('START SLAVE', false, '', ['fixed_connection' => true]);
		if ($rs)
		{
			$ob = new CClusterDBNode;
			$ob->Update($arNode['ID'], ['STATUS' => 'ONLINE']);
		}
	}

	public static function Stop($node_id)
	{
		global $DB;

		$arNode = CClusterDBNode::GetByID($node_id);
		if (!is_array($arNode))
		{
			return false;
		}

		if ($node_id == 1)
		{
			$nodeDB = $DB;
		}
		else
		{
			ob_start();
			$nodeDB = CDatabase::GetDBNodeConnection($arNode['ID'], true, false);
			ob_end_clean();
		}

		if (!is_object($nodeDB))
		{
			return false;
		}

		$rs = $nodeDB->Query('STOP SLAVE', false, '', ['fixed_connection' => true]);
		if ($rs)
		{
			$ob = new CClusterDBNode;
			if ($node_id == 1)
			{
				$res = $ob->Update($arNode['ID'], ['MASTER_ID' => false, 'STATUS' => 'ONLINE']);
			}
			else
			{
				$res = $ob->Update($arNode['ID'], ['STATUS' => 'READY']);
			}

			return $res;
		}
		else
		{
			return false;
		}
	}

	public static function SkipSQLError($node_id)
	{
		global $DB;

		$arNode = CClusterDBNode::GetByID($node_id);
		if (is_array($arNode))
		{
			if ($node_id == 1)
			{
				$nodeDB = $DB;
			}
			else
			{
				ob_start();
				$nodeDB = CDatabase::GetDBNodeConnection($arNode['ID'], true, false);
				ob_end_clean();
			}

			if (is_object($nodeDB))
			{
				//TODO check if started just make active
				$rs = $nodeDB->Query('STOP SLAVE', false, '', ['fixed_connection' => true]);

				if ($rs)
				{
					$rs = $nodeDB->Query('SET GLOBAL SQL_SLAVE_SKIP_COUNTER = 1', true, '', ['fixed_connection' => true]);
					if (!$rs)
					{
						return $nodeDB->GetErrorMessage();
					}
				}

				if ($rs)
				{
					$nodeDB->Query('START SLAVE', false, '', ['fixed_connection' => true]);
				}
			}
		}

		return true;
	}

	public static function GetStatus($node_id, $bSlaveStatus = true, $bGlobalStatus = true, $bVariables = true)
	{
		global $DB;

		$arNode = CClusterDBNode::GetByID($node_id);
		if (!is_array($arNode))
		{
			return false;
		}

		if ($node_id == 1)
		{
			$nodeDB = $DB;
		}
		else
		{
			ob_start();
			try
			{
				$nodeDB = CDatabase::GetDBNodeConnection($node_id, true, false);
			}
			catch (\Bitrix\Main\DB\ConnectionException $_)
			{
				$nodeDB = false;
			}
			ob_end_clean();
		}

		if (!is_object($nodeDB))
		{
			return false;
		}

		$arStatus = ['server_id' => null];

		if ($bVariables)
		{
			$rs = $nodeDB->Query("show variables like 'server_id'", false, '', ['fixed_connection' => true]);
			if ($ar = $rs->Fetch())
			{
				$arStatus['server_id'] = $ar['Value'];
			}
		}

		$cacheID = 'slave_for_master';
		$existSlave = false;
		$managedCache = \Bitrix\Main\Application::getInstance()->getManagedCache();

		if (
			CACHED_b_cluster_dbnode !== false
			&& $managedCache->read(CACHED_b_cluster_dbnode, $cacheID, 'b_cluster_dbnode')
		)
		{
			$existSlave = $managedCache->get($cacheID);
		}
		else
		{
			if (CClusterDBNode::GetList([], ['=MASTER_ID' => $node_id])->Fetch())
			{
				$existSlave = true;
			}
			$managedCache->set($cacheID, $existSlave);
		}

		if ($existSlave)
		{
			$arStatus = array_merge($arStatus, [
				'File' => null,
				'Position' => null,
			]);

			if ($bSlaveStatus)
			{
				$rs = $nodeDB->Query('SHOW MASTER STATUS', true, '', ['fixed_connection' => true]);
				if (!$rs)
				{
					return GetMessage('CLU_NO_PRIVILEGES', ['#sql#' => "GRANT REPLICATION CLIENT on *.* to '" . $nodeDB->DBLogin . "'@'%';"]);
				}

				$ar = $rs->Fetch();
				if (is_array($ar))
				{
					foreach ($ar as $key => $value)
					{
						if ($key == 'Last_Error')
						{
							$key = 'Last_SQL_Error';
						}

						if (array_key_exists($key, $arStatus))
						{
							$arStatus[$key] = $value;
						}
					}
				}
			}
		}

		if ($arNode['MASTER_ID'] <> '')
		{
			$arStatus = array_merge($arStatus, [
				'Slave_IO_State' => null,
				'Slave_IO_Running' => null,
				'Read_Master_Log_Pos' => null,
				'Slave_SQL_Running' => null,
				'Exec_Master_Log_Pos' => null,
				'Seconds_Behind_Master' => null,
				'Last_IO_Error' => null,
				'Last_SQL_Error' => null,
				'Com_select' => null,
			]);

			if ($bSlaveStatus)
			{
				$rs = $nodeDB->Query('SHOW SLAVE STATUS', true, '', ['fixed_connection' => true]);
				if (!$rs)
				{
					return GetMessage('CLU_NO_PRIVILEGES', ['#sql#' => "GRANT REPLICATION CLIENT on *.* to '" . $nodeDB->DBLogin . "'@'%';"]);
				}

				$ar = $rs->Fetch();
				if (is_array($ar))
				{
					foreach ($ar as $key => $value)
					{
						if ($key == 'Last_Error')
						{
							$key = 'Last_SQL_Error';
						}

						if (array_key_exists($key, $arStatus))
						{
							$arStatus[$key] = $value;
						}
					}
				}
			}
		}

		if ($bGlobalStatus)
		{
			$rs = $nodeDB->Query("show global status where Variable_name in ('Com_select', 'Com_do')", true, '', ['fixed_connection' => true]);
			if (is_object($rs))
			{
				while ($ar = $rs->Fetch())
				{
					if (!isset($arStatus['Com_select']))
					{
						$arStatus['Com_select'] = 0;
					}

					if ($ar['Variable_name'] == 'Com_do')
					{
						$arStatus['Com_select'] -= $ar['Value'] * 2;
					}
					else
					{
						$arStatus['Com_select'] += $ar['Value'];
					}
				}
			}
			else
			{
				$rs = $nodeDB->Query("show status like 'Com_select'", false, '', ['fixed_connection' => true]);
				$ar = $rs->Fetch();
				if ($ar)
				{
					$arStatus['Com_select'] += $ar['Value'];
				}

				$rs = $nodeDB->Query("show status like 'Com_do'", false, '', ['fixed_connection' => true]);
				$ar = $rs->Fetch();
				if ($ar)
				{
					$arStatus['Com_select'] -= $ar['Value'] * 2;
				}
			}
		}

		return $arStatus;
	}

	public static function GetList(): array
	{
		global $DB;
		static $slaves = false;
		if ($slaves === false)
		{
			$cacheID = 'db_slaves_v2';

			/** @var \Bitrix\Main\Data\ManagedCache $cache */
			$cache = Application::getInstance()->getManagedCache();
			if (
				CACHED_b_cluster_dbnode !== false
				&& $cache->read(CACHED_b_cluster_dbnode, $cacheID, 'b_cluster_dbnode')
			)
			{
				$slaves = $cache->get($cacheID);
			}
			else
			{
				$slaves = [];

				$rs = $DB->Query("
					SELECT ID, WEIGHT, ROLE_ID, GROUP_ID
					FROM b_cluster_dbnode
					WHERE STATUS = 'ONLINE' AND (SELECTABLE is null or SELECTABLE = 'Y')
					ORDER BY ID
				", false, '', ['fixed_connection' => true]);
				while ($ar = $rs->Fetch())
				{
					$slaves[intval($ar['ID'])] = $ar;
				}

				if (CACHED_b_cluster_dbnode !== false)
				{
					$cache->set($cacheID, $slaves);
				}
			}
		}
		return $slaves;
	}

	/**
	 * @param array $arNode
	 * @param CDatabase $nodeDB
	 */
	public static function AdjustServerID($arNode, $nodeDB)
	{
		$rs = $nodeDB->Query("show variables like 'server_id'", false, '', ['fixed_connection' => true]);
		if ($ar = $rs->Fetch())
		{
			if ($ar['Value'] != $arNode['SERVER_ID'])
			{
				$ob = new CClusterDBNode;
				$ob->Update($arNode['ID'], ['SERVER_ID' => $ar['Value']]);
			}
		}
	}

	protected static function GetMaxSlaveDelay(): int
	{
		static $max_slave_delay = null;
		if (!isset($max_slave_delay))
		{
			$max_slave_delay = (int) Option::get('cluster', 'max_slave_delay');
			if (
				Application::getInstance()->isInitialized()
				&& isset(Application::getInstance()->getKernelSession()['BX_REDIRECT_TIME'])
			)
			{
				$redirect_delay = time() - Application::getInstance()->getKernelSession()['BX_REDIRECT_TIME'] + 1;
				if (
					$redirect_delay > 0
					&& $redirect_delay < $max_slave_delay
				)
				{
					$max_slave_delay = $redirect_delay;
				}
			}
		}
		return $max_slave_delay;
	}

	protected static function IsSlaveOk($slave_id): bool
	{
		$cache = \Bitrix\Main\Data\Cache::createInstance();
		if ($cache->initCache(
			(int) Option::get('cluster', 'slave_status_cache_time'),
			'cluster_slave_status_' . (int) $slave_id,
			'cluster'
		))
		{
			$slaveStatus = $cache->getVars();
		}
		else
		{
			$slaveStatus = static::GetStatus($slave_id, true, false, false);
		}

		if (
			!$slaveStatus
			|| $slaveStatus['Seconds_Behind_Master'] > static::GetMaxSlaveDelay()
			|| $slaveStatus['Last_SQL_Error'] != ''
			|| $slaveStatus['Last_IO_Error'] != ''
			|| $slaveStatus['Slave_SQL_Running'] === 'No'
		)
		{
			if ($cache->startDataCache())
			{
				$cache->endDataCache($slaveStatus);
			}
			return false;
		}
		return true;
	}

	public static function GetRandomNode()
	{
		$slaves = static::GetList();
		if (empty($slaves))
		{
			return false;
		}

		//Exclude slaves from other cluster groups
		foreach ($slaves as $i => $slave)
		{
			$isOtherGroup = defined('BX_CLUSTER_GROUP') && ($slave['GROUP_ID'] != constant('BX_CLUSTER_GROUP'));
			if (
				defined('BX_CLUSTER_SLAVE_USE_ANY_GROUP')
				&& constant('BX_CLUSTER_SLAVE_USE_ANY_GROUP') === true
				&& $slave['ROLE_ID'] == 'SLAVE'
			)
			{
				$isOtherGroup = false;
			}

			if ($isOtherGroup)
			{
				unset($slaves[$i]);
			}
		}

		$found = false;
		while (true)
		{
			if (empty($slaves))
			{
				return false;
			}

			$total_weight = 0;
			foreach ($slaves as $i => $slave)
			{
				$total_weight += $slave['WEIGHT'];
				$slaves[$i]['PIE_WEIGHT'] = $total_weight;
			}

			$rand = ($total_weight > 0 ? mt_rand(1, $total_weight) : 0);
			foreach ($slaves as $i => $slave)
			{
				if ($rand <= $slave['PIE_WEIGHT'])
				{
					if ($slave['ROLE_ID'] == 'SLAVE')
					{
						if (!static::IsSlaveOk($slave['ID']))
						{
							unset($slaves[$i]);
							continue 2;
						}
					}

					$found = $slave;
					break 2;
				}
			}
		}

		if (!$found || $found['ROLE_ID'] != 'SLAVE')
		{
			return false; //use main connection
		}

		return $found;
	}
}
