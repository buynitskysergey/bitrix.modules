<?php
IncludeModuleLangFile(__FILE__);

class CClusterDBNodeCheck extends CAllClusterDBNodeCheck
{
	const OK = 1;
	const WARNING = 0;
	const ERROR = -1;

	public function MainNodeCommon($arMasterNode)
	{
		if ($arMasterNode['ID'] == 1)
		{
			global $DB;
		}
		else
		{
			$DB = CDatabase::GetDBNodeConnection($arMasterNode['ID'], true);
		}

		$result = [];

		$is_ok  = CCluster::checkForServers(1);
		$result['server_count'] = [
			'IS_OK' => $is_ok ? CClusterDBNodeCheck::OK : CClusterDBNodeCheck::WARNING,
			'MESSAGE' => GetMessage('CLUSTER_SERVER_COUNT_CHECK'),
			'WIZ_REC' => '',
		];

		$is_ok = !file_exists($_SERVER['DOCUMENT_ROOT'] . BX_PERSONAL_ROOT . '/php_interface/after_connect.php');
		$result['after_connect'] = [
			'IS_OK' => $is_ok ? CClusterDBNodeCheck::OK : CClusterDBNodeCheck::ERROR,
			'MESSAGE' => GetMessage('CLU_AFTER_CONNECT_MSG'),
			'WIZ_REC' => GetMessage('CLU_AFTER_CONNECT_WIZREC'),
		];

		$is_ok = !file_exists($_SERVER['DOCUMENT_ROOT'] . BX_PERSONAL_ROOT . '/php_interface/after_connect_d7.php');
		$result['after_connect_d7'] = [
			'IS_OK' => $is_ok ? CClusterDBNodeCheck::OK : CClusterDBNodeCheck::ERROR,
			'MESSAGE' => GetMessage('CLU_AFTER_CONNECT_D7_MSG'),
			'WIZ_REC' => GetMessage('CLU_AFTER_CONNECT_WIZREC'),
		];

		$arVariables = static::GetServerVariables($DB, [
			'character_set_server' => '',
			'character_set_database' => '',
			'character_set_connection' => '',
			'character_set_client' => '',
		], 'character_set%');
		$is_ok = $arVariables['character_set_server'] !== ''
			&& $arVariables['character_set_server'] === $arVariables['character_set_database']
			&& $arVariables['character_set_database'] === $arVariables['character_set_connection']
			&& $arVariables['character_set_connection'] === $arVariables['character_set_client']
		;
		$is_ok = $is_ok || (defined('BX_CLUSTER_SKIP_CHARSET_CHECK') && constant('BX_CLUSTER_SKIP_CHARSET_CHECK') === true);
		$result['charset'] = [
			'IS_OK' => $is_ok ? CClusterDBNodeCheck::OK : CClusterDBNodeCheck::ERROR,
			'MESSAGE' => GetMessage('CLU_CHARSET_MSG'),
			'WIZ_REC' => GetMessage('CLU_CHARSET_WIZREC', [
				'#character_set_server#' => $arVariables['character_set_server'],
				'#character_set_database#' => $arVariables['character_set_database'],
				'#character_set_connection#' => $arVariables['character_set_connection'],
				'#character_set_client#' => $arVariables['character_set_client'],
			]),
		];

		$arVariables = static::GetServerVariables($DB, [
			'collation_server' => '',
			'collation_database' => '',
			'collation_connection' => '',
		], 'collation%');
			$is_ok = $arVariables['collation_server'] !== ''
				&& $arVariables['collation_server'] === $arVariables['collation_database']
				&& $arVariables['collation_database'] === $arVariables['collation_connection']
		;
		$is_ok = $is_ok || (defined('BX_CLUSTER_SKIP_CHARSET_CHECK') && constant('BX_CLUSTER_SKIP_CHARSET_CHECK') === true);
		$result['collation'] = [
			'IS_OK' => $is_ok ? CClusterDBNodeCheck::OK : CClusterDBNodeCheck::ERROR,
			'MESSAGE' => GetMessage('CLU_COLLATION_MSG'),
			'WIZ_REC' => GetMessage('CLU_COLLATION_WIZREC', [
				'#collation_server#' => $arVariables['collation_server'],
				'#collation_database#' => $arVariables['collation_database'],
				'#collation_connection#' => $arVariables['collation_connection'],
			]),
		];

		return $result;
	}

	public function MainNodeForReplication($arMasterNode)
	{
		if ($arMasterNode['ID'] == 1)
		{
			global $DB;
		}
		else
		{
			$DB = CDatabase::GetDBNodeConnection($arMasterNode['ID'], true);
		}

		$result = [];

		$server_id = $this->GetServerVariable($DB, 'server_id');
		$is_ok = $server_id > 0;
		$result['server_id'] = [
			'IS_OK' => $is_ok ? CClusterDBNodeCheck::OK : CClusterDBNodeCheck::ERROR,
			'MESSAGE' => GetMessage('CLU_SERVER_ID_MSG', ['#server-id#' => $server_id]),
			'WIZ_REC' => GetMessage('CLU_SERVER_ID_WIZREC'),
		];

		$log_bin = $this->GetServerVariable($DB, 'log_bin');
		$is_ok = $log_bin === 'ON';
		$result['log_bin'] = [
			'IS_OK' => $is_ok ? CClusterDBNodeCheck::OK : CClusterDBNodeCheck::ERROR,
			'MESSAGE' => GetMessage('CLU_LOG_BIN_MSG', ['#log-bin#' => $log_bin]),
			'WIZ_REC' => GetMessage('CLU_LOG_BIN_WIZREC'),
		];

		$skip_networking = $this->GetServerVariable($DB, 'skip_networking');
		$is_ok = $skip_networking === 'OFF';
		$result['skip_networking'] = [
			'IS_OK' => $is_ok ? CClusterDBNodeCheck::OK : CClusterDBNodeCheck::ERROR,
			'MESSAGE' => GetMessage('CLU_SKIP_NETWORKING_MSG', ['#skip-networking#' => $skip_networking]),
			'WIZ_REC' => GetMessage('CLU_SKIP_NETWORKING_WIZREC'),
		];

		$innodb_flush_log_at_trx_commit = $this->GetServerVariable($DB, 'innodb_flush_log_at_trx_commit');
		$is_ok = $innodb_flush_log_at_trx_commit === '1';
		$result['innodb_flush_log_at_trx_commit'] = [
			'IS_OK' => $is_ok ? CClusterDBNodeCheck::OK : CClusterDBNodeCheck::WARNING,
			'MESSAGE' => GetMessage('CLU_FLUSH_ON_COMMIT_MSG', ['#innodb_flush_log_at_trx_commit#' => $innodb_flush_log_at_trx_commit]),
			'WIZ_REC' => '',
		];

		$sync_binlog = $this->GetServerVariable($DB, 'sync_binlog');
		$is_ok = $sync_binlog === '1';
		$result['sync_binlog'] = [
			'IS_OK' => $is_ok ? CClusterDBNodeCheck::OK : CClusterDBNodeCheck::WARNING,
			'MESSAGE' => GetMessage('CLU_SYNC_BINLOG_MSG', ['#sync_binlog#' => $sync_binlog]),
			'WIZ_REC' => '',
		];

		$master_version = $this->GetServerVariable($DB, 'version');
		$isSuperRequred = version_compare($master_version, '8.0') < 0;

		$privileges = [];
		$rs = $DB->Query('SHOW GRANTS FOR CURRENT_USER');
		while ($ar = $rs->Fetch())
		{
			$privilege = array_pop($ar);
			if (preg_match('/^GRANT (.+?) ON/', $privilege, $match))
			{
				$privileges = array_merge($privileges, array_map('trim', explode(',', $match[1])));
			}
		}

		$grantList = [];
		if ($isSuperRequred && !in_array('SUPER', $privileges, true))
		{
			$grantList[] = 'SUPER';
		}
		if (!in_array('REPLICATION CLIENT', $privileges, true))
		{
			$grantList[] = 'REPLICATION CLIENT';
		}
		if (!$isSuperRequred && !in_array('REPLICATION_SLAVE_ADMIN', $privileges, true))
		{
			$grantList[] = 'REPLICATION_SLAVE_ADMIN';
		}

		$result['privilege'] = [
			'IS_OK' => $grantList ? CClusterDBNodeCheck::ERROR : CClusterDBNodeCheck::OK,
			'MESSAGE' => GetMessage('CLU_SLAVE_PRIVILEGE_MSG'),
			'WIZ_REC' => ($grantList ? GetMessage('CLU_MASTER_STATUS_WIZREC', [
				'#sql#' => 'GRANT ' . implode(', ', $grantList) . " on *.* to '" . $DB->DBLogin . "'@'%';",
			]) : ''),
		];

		$DatabaseName = $DB->DBName;
		$is_ok = false;
		$rsBinLogs = $DB->Query('show master status', true, '', ['fixed_connection' => true]);
		if (!$rsBinLogs)
		{
			$result['master_status'] = [
				'IS_OK' => CClusterDBNodeCheck::ERROR,
				'MESSAGE' => GetMessage('CLU_MASTER_STATUS_MSG'),
				'WIZ_REC' => GetMessage('CLU_MASTER_STATUS_WIZREC', ['#sql#' => "GRANT REPLICATION CLIENT on *.* to '" . $DB->DBLogin . "'@'%';"]),
			];
		}
		else
		{
			if ($ar = $rsBinLogs->Fetch())
			{
				$Binlog_Do_DB = implode(',', array_keys(array_flip(explode(',', $ar['Binlog_Do_DB']))));
				if ($Binlog_Do_DB === $DatabaseName)
				{
					$is_ok = true;
				}
			}
			if ($rsBinLogs->Fetch())
			{
					$is_ok = false;
			}

			$result['binlog_do_db'] = [
				'IS_OK' => $is_ok ? CClusterDBNodeCheck::OK : CClusterDBNodeCheck::WARNING,
				'MESSAGE' => GetMessage('CLU_SYNC_BINLOGDODB_MSG'),
				'WIZ_REC' => GetMessage('CLU_SYNC_BINLOGDODB_WIZREC', ['#database#' => $DatabaseName]),
			];
		}

		return $result;
	}

	public function MainNodeForSlave()
	{
		global $DB;
		$result = [];

		$arMasters = [];
		$rsData = CClusterDBNode::GetList(
			[
				'ID' => 'ASC',
			],
			[
				'=ROLE_ID' => ['MAIN', 'MASTER'],
			]
		);
		while ($arData = $rsData->Fetch())
		{
			$arMasters[$arData['ID']] = $arData;
		}

		foreach ($arMasters as $node_id => $arNode)
		{
			if ($node_id == 1)
			{
				$nodeDB = $DB;
			}
			else
			{
				$nodeDB = CDatabase::GetDBNodeConnection($node_id, true);
			}
			$arMasters[$node_id]['DB'] = $nodeDB;
		}

		$auto_increment_increment = count($arMasters) + 1;
		$bIncIsOK = true;
		foreach ($arMasters as $node_id => $arNode)
		{
			$inc = $this->GetServerVariable($arNode['DB'], 'auto_increment_increment');
			if ($inc != $auto_increment_increment)
			{
				$bIncIsOK = false;
				$result[$node_id . '_auto_increment_increment'] = [
					'IS_OK' => CClusterDBNodeCheck::ERROR,
					'MESSAGE' => GetMessage('CLU_AUTO_INCREMENT_INCREMENT_ERR_MSG', ['#node_id#' => $node_id, '#value#' => $auto_increment_increment, '#current#' => $inc]),
					'WIZ_REC' => GetMessage('CLU_AUTO_INCREMENT_INCREMENT_WIZREC', ['#value#' => $auto_increment_increment]),
				];
			}
		}
		if ($bIncIsOK)
		{
			$result['_auto_increment_increment'] = [
				'IS_OK' => CClusterDBNodeCheck::OK,
				'MESSAGE' => GetMessage('CLU_AUTO_INCREMENT_INCREMENT_OK_MSG', ['#value#' => $auto_increment_increment]),
				'WIZ_REC' => '',
			];
		}

		$auto_increment_offset = [];
		$bIncIsOK = true;
		foreach ($arMasters as $node_id => $arNode)
		{
			$offset = $this->GetServerVariable($arNode['DB'], 'auto_increment_offset');
			$mod = $offset % $auto_increment_increment;
			if (array_key_exists($mod, $auto_increment_offset))
			{
				$bIncIsOK = false;
				$result[$node_id . '_auto_increment_offset'] = [
					'IS_OK' => CClusterDBNodeCheck::ERROR,
					'MESSAGE' => GetMessage('CLU_AUTO_INCREMENT_OFFSET_ERR_MSG', ['#node_id#' => $node_id, '#current#' => $offset]),
					'WIZ_REC' => GetMessage('CLU_AUTO_INCREMENT_OFFSET_WIZREC', ['#current#' => $offset]),
				];
			}
			else
			{
				$auto_increment_offset[$mod] = $node_id;
			}
		}
		if ($bIncIsOK)
		{
			$result['_auto_increment_offset'] = [
				'IS_OK' => CClusterDBNodeCheck::OK,
				'MESSAGE' => GetMessage('CLU_AUTO_INCREMENT_OFFSET_OK_MSG', ['#value#' => $auto_increment_increment]),
				'WIZ_REC' => '',
			];
		}

		$bRelayIsOK = true;
		foreach ($arMasters as $node_id => $arNode)
		{
			$relay_log = $this->GetServerVariable($arNode['DB'], 'relay_log');
			if ($relay_log == '')
			{
				$bRelayIsOK = false;
				$result[$node_id . '_relay_log'] = [
					'IS_OK' => CClusterDBNodeCheck::ERROR,
					'MESSAGE' => GetMessage('CLU_RELAY_LOG_ERR_MSG', ['#node_id#' => $node_id, '#relay-log#' => $relay_log]),
					'WIZ_REC' => GetMessage('CLU_RELAY_LOG_WIZREC'),
				];
			}
		}
		if ($bRelayIsOK)
		{
			$result['_relay_log'] = [
				'IS_OK' => CClusterDBNodeCheck::OK,
				'MESSAGE' => GetMessage('CLU_RELAY_LOG_OK_MSG'),
				'WIZ_REC' => '',
			];
		}

		$bSlaveUpdatesIsOK = true;
		foreach ($arMasters as $node_id => $arNode)
		{
			$log_slave_updates = $this->GetServerVariable($arNode['DB'], 'log_slave_updates');
			if ($log_slave_updates !== 'ON')
			{
				$bSlaveUpdatesIsOK = false;
				$result[$node_id . '_log_slave_updates'] = [
					'IS_OK' => CClusterDBNodeCheck::ERROR,
					'MESSAGE' => GetMessage('CLU_LOG_SLAVE_UPDATES_MSG', ['#node_id#' => $node_id, '#log-slave-updates#' => $log_slave_updates]),
					'WIZ_REC' => GetMessage('CLU_LOG_SLAVE_UPDATES_WIZREC', ['#value#' => 1]),
				];
			}
		}
		if ($bSlaveUpdatesIsOK)
		{
			$result['_log_slave_updates'] = [
				'IS_OK' => CClusterDBNodeCheck::OK,
				'MESSAGE' => GetMessage('CLU_LOG_SLAVE_UPDATES_OK_MSG'),
				'WIZ_REC' => '',
			];
		}

		return $result;
	}

	public function SlaveNodeIsReplicationRunning($db_host, $db_name, $db_login, $db_password, $master_host=false, $master_port=false)
	{
		$node_id = 'v99';
		CClusterDBNode::GetByID($node_id, [
			'ACTIVE' => 'Y',
			'STATUS' => 'ONLINE',
			'DB_HOST' => $db_host,
			'DB_NAME' => $db_name,
			'DB_LOGIN' => $db_login,
			'DB_PASSWORD' => $db_password,
		]);

		try
		{
			ob_start();
			$nodeDB = CDatabase::GetDBNodeConnection($node_id, true);
			$error = ob_get_contents();
			ob_end_clean();
		}
		catch (\Bitrix\Main\DB\ConnectionException $e)
		{
			$nodeDB = false;
			$error = $e->getMessage();
		}

		if (is_object($nodeDB))
		{
			//Check if replication is runnung
			$rs = $nodeDB->Query('show slave status', true, '', ['fixed_connection' => true]);
			$ar = $rs ? $rs->Fetch() : false;

			if ($ar && $ar['Slave_IO_State'] <> '')
			{
				if ($ar['Master_Host'] == $master_host && $ar['Master_Port'] == $master_port)
				{
					return $nodeDB;
				}
				else
				{
					return GetMessage('CLU_RUNNING_SLAVE');
				}
			}
			else
			{
				return false;
			}
		}
		else
		{
			return $error;
		}
	}

	public function SlaveNodeConnection($db_host, $db_name, $db_login, $db_password, $master_host=false, $master_port=false, $master_id = 1)
	{
		global $DB;

		$node_id = 'v99';
		CClusterDBNode::GetByID($node_id, [
			'ACTIVE' => 'Y',
			'STATUS' => 'ONLINE',
			'DB_HOST' => $db_host,
			'DB_NAME' => $db_name,
			'DB_LOGIN' => $db_login,
			'DB_PASSWORD' => $db_password,
		]);

		try
		{
			ob_start();
			$nodeDB = CDatabase::GetDBNodeConnection($node_id, true);
			$error = ob_get_contents();
			ob_end_clean();
		}
		catch (\Bitrix\Main\DB\ConnectionException $e)
		{
			$nodeDB = false;
			$error = $e->getMessage();
		}

		if (is_object($nodeDB))
		{
			//Test if this connection is not the same as master
			$bSkipSecondTest = false;
			//1. Make sure that no replication is runnung
			$rs = $nodeDB->Query('show slave status', true, '', ['fixed_connection' => true]);
			$ar = $rs ? $rs->Fetch() : false;
			if ($ar)
			{
				if ($ar['Slave_IO_State'] <> '')
				{
					if ($ar['Master_Host'] != $master_host || $ar['Master_Port'] != $master_port)
					{
						return GetMessage('CLU_RUNNING_SLAVE');
					}
					else
					{
						$bSkipSecondTest = true; //The replication is OK
					}
				}
			}
			//2. Check if b_cluster_dbnode exists on node
			if ($nodeDB->TableExists('b_cluster_dbnode') && !$bSkipSecondTest)
			{
				//2.1 Generate uniq id
				$uniqid = md5(mt_rand());
				$DB->Query("UPDATE b_cluster_dbnode SET UNIQID='" . $uniqid . "' WHERE ID=1", false, '', ['fixed_connection' => true]);
				$rs = $nodeDB->Query('SELECT UNIQID FROM b_cluster_dbnode WHERE ID=1', true);
				if ($rs)
				{
					if ($ar = $rs->Fetch())
					{
						if ($ar['UNIQID'] == $uniqid)
						{
							return GetMessage('CLU_SAME_DATABASE');
						}
					}
				}
			}
			//3. Check master connect
			if ($master_host !== false && $master_port !== false)
			{
				$node_id = 'v98';
				if ($master_id == 1)
				{
					CClusterDBNode::GetByID($node_id, [
						'ACTIVE' => 'Y',
						'STATUS' => 'ONLINE',
						'DB_HOST' => $master_host . ':' . $master_port,
						'DB_NAME' => $DB->DBName,
						'DB_LOGIN' => $DB->DBLogin,
						'DB_PASSWORD' => $DB->DBPassword,
					]);
				}
				else
				{
					$node_id = $master_id;
				}

				ob_start();
				$masterDB = CDatabase::GetDBNodeConnection($node_id, true);
				$error = ob_get_contents();
				ob_end_clean();
				if (is_object($masterDB))
				{
					//3.1 Check if b_cluster_dbnode is the same as on master
					if (!$masterDB->TableExists('b_cluster_dbnode'))
					{
						return GetMessage('CLU_NOT_MASTER');
					}

					//3.2 Generate uniq id
					$uniqid = md5(mt_rand());
					$DB->Query("UPDATE b_cluster_dbnode SET UNIQID='" . $uniqid . "' WHERE ID=1", false, '', ['fixed_connection' => true]);

					$Seconds_Behind_Master = 1;
					$rs = $masterDB->Query('SHOW SLAVE STATUS');
					if ($ar = $rs->Fetch())
					{
						if ($ar['Seconds_Behind_Master'] > 0)
						{
							$Seconds_Behind_Master += $ar['Seconds_Behind_Master'];
						}
					}
					sleep($Seconds_Behind_Master);

					$rs = $masterDB->Query('SELECT UNIQID FROM b_cluster_dbnode WHERE ID=1', true, '', ['fixed_connection' => true]);
					if (!$rs)
					{
						return GetMessage('CLU_NOT_MASTER');
					}

					$ar = $rs->Fetch();
					if (!$ar)
					{
						return GetMessage('CLU_NOT_MASTER');
					}

					if ($ar['UNIQID'] != $uniqid)
					{
						return GetMessage('CLU_NOT_MASTER');
					}
				}
				else
				{
					return GetMessage('CLU_MASTER_CONNECT_ERROR') . $error;
				}
			}

			return $nodeDB;
		}
		else
		{
			return $error;
		}
	}

	public function SlaveNodeCommon($nodeDB)
	{
		$result = [];

		global $DB;
		$main_character_set_server = $this->GetServerVariable($DB, 'character_set_server');
		$main_collation_server = $this->GetServerVariable($DB, 'collation_server');

		$arCharset = static::GetServerVariables($nodeDB, [
			'character_set_server' => '',
			'character_set_database' => '',
			'character_set_connection' => '',
			'character_set_client' => '',
		], 'character_set%');

		$arCollation = static::GetServerVariables($nodeDB, [
			'collation_server' => '',
			'collation_database' => '',
			'collation_connection' => '',
		], 'collation%');

		$is_ok = $main_character_set_server === $arCharset['character_set_server']
			&& $main_collation_server === $arCollation['collation_server']
		;
		$is_ok = $is_ok || (defined('BX_CLUSTER_SKIP_CHARSET_CHECK') && constant('BX_CLUSTER_SKIP_CHARSET_CHECK') === true);
		$result['master_charset'] = [
			'IS_OK' => $is_ok ? CClusterDBNodeCheck::OK : CClusterDBNodeCheck::ERROR,
			'MESSAGE' => GetMessage('CLU_MASTER_CHARSET_MSG'),
			'WIZ_REC' => GetMessage('CLU_MASTER_CHARSET_WIZREC', [
				'#character_set_server#' => $arCharset['character_set_server'],
				'#collation_server#' => $arCollation['collation_server'],
			]),
		];

		$is_ok = $arCharset['character_set_server'] !== ''
			&& $arCharset['character_set_server'] === $arCharset['character_set_database']
			&& $arCharset['character_set_database'] === $arCharset['character_set_connection']
			&& $arCharset['character_set_connection'] === $arCharset['character_set_client']
		;
		$is_ok = $is_ok || (defined('BX_CLUSTER_SKIP_CHARSET_CHECK') && constant('BX_CLUSTER_SKIP_CHARSET_CHECK') === true);
		$result['charset'] = [
			'IS_OK' => $is_ok ? CClusterDBNodeCheck::OK : CClusterDBNodeCheck::ERROR,
			'MESSAGE' => GetMessage('CLU_CHARSET_MSG'),
			'WIZ_REC' => GetMessage('CLU_CHARSET_WIZREC', [
				'#character_set_server#' => $arCharset['character_set_server'],
				'#character_set_database#' => $arCharset['character_set_database'],
				'#character_set_connection#' => $arCharset['character_set_connection'],
				'#character_set_client#' => $arCharset['character_set_client'],
			]),
		];

		$is_ok = $arCollation['collation_server'] !== ''
			&& $arCollation['collation_server'] === $arCollation['collation_database']
			&& $arCollation['collation_database'] === $arCollation['collation_connection']
		;
		$is_ok = $is_ok || (defined('BX_CLUSTER_SKIP_CHARSET_CHECK') && constant('BX_CLUSTER_SKIP_CHARSET_CHECK') === true);
		$result['collation'] = [
			'IS_OK' => $is_ok ? CClusterDBNodeCheck::OK : CClusterDBNodeCheck::ERROR,
			'MESSAGE' => GetMessage('CLU_COLLATION_MSG'),
			'WIZ_REC' => GetMessage('CLU_COLLATION_WIZREC', [
				'#collation_server#' => $arCollation['collation_server'],
				'#collation_database#' => $arCollation['collation_database'],
				'#collation_connection#' => $arCollation['collation_connection'],
			]),
		];

		$arTestSQL = [
			0 => 'drop table b_cluster_test',
			'sql_create' => 'create table b_cluster_test(column1 int)',
			'sql_insert' => 'insert into b_cluster_test (column1) values (1)',
			'sql_select' => 'select * from b_cluster_test',
			'sql_update' => 'update b_cluster_test set column1=2 where column1=1',
			'sql_delete' => 'delete from b_cluster_test where column1=2',
			'sql_drop'   => 'drop table b_cluster_test',
		];
		$is_ok = true;
		$sql_erorrs_list = '';
		foreach ($arTestSQL as $id => $sql)
		{
			$res = $nodeDB->Query($sql, true);
			if (!$res && $id !== 0)
			{
				$is_ok = false;
				$sql_erorrs_list .= '<br />&nbsp;' . $sql . ': ' . $nodeDB->db_Error . "\n";
			}
		}
		$result['sql'] = [
			'IS_OK' => $is_ok ? CClusterDBNodeCheck::OK : CClusterDBNodeCheck::ERROR,
			'MESSAGE' => GetMessage('CLU_SQL_MSG'),
			'WIZ_REC' => GetMessage('CLU_SQL_WIZREC', [
				'#sql_erorrs_list#' => $sql_erorrs_list,
			]),
		];

		$required_version = '5.0.0';
		$slave_version = $this->GetServerVariable($nodeDB, 'version');
		$is_ok = version_compare($required_version, $slave_version) <= 0;
		$result['version'] = [
			'IS_OK' => $is_ok ? CClusterDBNodeCheck::OK : CClusterDBNodeCheck::ERROR,
			'MESSAGE' => GetMessage('CLU_SLAVE_VERSION_MSG', [
				'#slave-version#' => $slave_version,
				'#required-version#' => $required_version,
			]),
			'WIZ_REC' => GetMessage('CLU_VERSION_WIZREC'),
		];

		return $result;
	}

	public function SlaveNodeForReplication($nodeDB)
	{
		global $DB;

		$result = [];

		$main_server_id = intval($this->GetServerVariable($DB, 'server_id'));
		$node_server_id = intval($this->GetServerVariable($nodeDB, 'server_id'));
		$is_ok = $node_server_id > 0 && $main_server_id != $node_server_id;
		if ($is_ok)
		{
			$rsNodes = CClusterDBNode::GetList([], [
				'=SERVER_ID' => $node_server_id,
				'!=MASTER_ID' => false,
			]);
			$is_ok2 = !is_array($rsNodes->Fetch());
		}
		else
		{
			$is_ok2 = true;
		}

		$result['server_id'] = [
			'IS_OK' => $is_ok && $is_ok2 ? CClusterDBNodeCheck::OK : CClusterDBNodeCheck::ERROR,
			'MESSAGE' => GetMessage('CLU_SERVER_ID_MSG', ['#server-id#' => $node_server_id]),
			'WIZ_REC' => ($is_ok ? '' : GetMessage('CLU_SERVER_ID_WIZREC1')) . ' '
				. ($is_ok2 ? '' : GetMessage('CLU_SERVER_ID_WIZREC2')) . ' '
				. GetMessage('CLU_SERVER_ID_WIZREC')
			,
		];

		$master_max_allowed_packet = $this->GetServerVariable($DB, 'max_allowed_packet');
		$slave_max_allowed_packet = $this->GetServerVariable($nodeDB, 'max_allowed_packet');
		$is_ok = $slave_max_allowed_packet >= $master_max_allowed_packet;
		$result['max_allowed_packet'] = [
			'IS_OK' => $is_ok ? CClusterDBNodeCheck::OK : CClusterDBNodeCheck::WARNING,
			'MESSAGE' => GetMessage('CLU_MAX_ALLOWED_PACKET_MSG'),
			'WIZ_REC' => GetMessage('CLU_MAX_ALLOWED_PACKET_WIZREC'),
		];

		$master_version = $this->GetServerVariable($DB, 'version');
		$slave_version = $this->GetServerVariable($nodeDB, 'version');
		$is_ok = version_compare($master_version, $slave_version) <= 0;
		$result['slave_version'] = [
			'IS_OK' => $is_ok ? CClusterDBNodeCheck::OK : CClusterDBNodeCheck::ERROR,
			'MESSAGE' => GetMessage('CLU_VERSION_MSG', [
				'#slave-version#' => $slave_version,
				'#master-version#' => $master_version,
			]),
			'WIZ_REC' => GetMessage('CLU_VERSION_WIZREC'),
		];

		$relay_log = $this->GetServerVariable($nodeDB, 'relay_log');
		$is_ok = $relay_log <> '';
		$result['relay_log'] = [
			'IS_OK' => $is_ok ? CClusterDBNodeCheck::OK : CClusterDBNodeCheck::WARNING,
			'MESSAGE' => GetMessage('CLU_SLAVE_RELAY_LOG_MSG'),
			'WIZ_REC' => GetMessage('CLU_RELAY_LOG_WIZREC'),
		];

		$isSuperRequred = version_compare($slave_version, '8.0') < 0;

		$privileges = [];
		$rs = $nodeDB->Query('SHOW GRANTS FOR CURRENT_USER');
		while ($ar = $rs->Fetch())
		{
			$privilege = array_pop($ar);
			if (preg_match('/^GRANT (.+?) ON/', $privilege, $match))
			{
				$privileges = array_merge($privileges, array_map('trim', explode(',', $match[1])));
			}
		}

		$grantList = [];
		if ($isSuperRequred && !in_array('SUPER', $privileges, true))
		{
			$grantList[] = 'SUPER';
		}
		if (!in_array('REPLICATION CLIENT', $privileges, true))
		{
			$grantList[] = 'REPLICATION CLIENT';
		}
		if (!in_array('REPLICATION SLAVE', $privileges, true))
		{
			$grantList[] = 'REPLICATION SLAVE';
		}
		if (!$isSuperRequred && !in_array('REPLICATION_SLAVE_ADMIN', $privileges, true))
		{
			$grantList[] = 'REPLICATION_SLAVE_ADMIN';
		}

		$result['privilege'] = [
			'IS_OK' => $grantList ? CClusterDBNodeCheck::ERROR : CClusterDBNodeCheck::OK,
			'MESSAGE' => GetMessage('CLU_SLAVE_PRIVILEGE_MSG'),
			'WIZ_REC' => ($grantList ? GetMessage('CLU_MASTER_STATUS_WIZREC', [
				'#sql#' => 'GRANT ' . implode(', ', $grantList) . " on *.* to '" . $nodeDB->DBLogin . "'@'%';",
			]) : ''),
		];

		return $result;
	}

	public function SlaveNodeForMaster($nodeDB)
	{
		global $DB;
		$result = [];

		$arMasters = [];

		$rsData = CClusterDBNode::GetList(
			[
				'ID' => 'ASC',
			],
			[
				'=ROLE_ID' => ['MAIN', 'MASTER'],
			]
		);
		while ($arData = $rsData->Fetch())
		{
			$arMasters[$arData['ID']] = $arData;
		}

		$arMasters['v99'] = [];//virtual connection must be alredy setup

		foreach ($arMasters as $node_id => $arNode)
		{
			if ($node_id == 1)
			{
				$nodeDB = $DB;
			}
			else
			{
				$nodeDB = CDatabase::GetDBNodeConnection($node_id, true);
			}
			$arMasters[$node_id]['DB'] = $nodeDB;
		}

		$auto_increment_increment = count($arMasters);
		$bIncIsOK = true;
		foreach ($arMasters as $node_id => $arNode)
		{
			$inc = $this->GetServerVariable($arNode['DB'], 'auto_increment_increment');
			if ($inc != $auto_increment_increment)
			{
				$bIncIsOK = false;
				if ($node_id == 'v99')
				{
					$result[$node_id . '_auto_increment_increment'] = [
						'IS_OK' => CClusterDBNodeCheck::ERROR,
						'MESSAGE' => GetMessage('CLU_AUTO_INCREMENT_INCREMENT_NODE_ERR_MSG', ['#value#' => $auto_increment_increment, '#current#' => $inc]),
						'WIZ_REC' => GetMessage('CLU_AUTO_INCREMENT_INCREMENT_WIZREC', ['#value#' => $auto_increment_increment]),
					];
				}
				else
				{
					$result[$node_id . '_auto_increment_increment'] = [
						'IS_OK' => CClusterDBNodeCheck::ERROR,
						'MESSAGE' => GetMessage('CLU_AUTO_INCREMENT_INCREMENT_ERR_MSG', ['#node_id#' => $node_id, '#value#' => $auto_increment_increment, '#current#' => $inc]),
						'WIZ_REC' => GetMessage('CLU_AUTO_INCREMENT_INCREMENT_WIZREC', ['#value#' => $auto_increment_increment]),
					];
				}
			}
		}
		if ($bIncIsOK)
		{
			$result['_auto_increment_increment'] = [
				'IS_OK' => CClusterDBNodeCheck::OK,
				'MESSAGE' => GetMessage('CLU_AUTO_INCREMENT_INCREMENT_OK_MSG', ['#value#' => $auto_increment_increment]),
				'WIZ_REC' => '',
			];
		}

		$auto_increment_offset = [];
		$bIncIsOK = true;
		foreach ($arMasters as $node_id => $arNode)
		{
			$offset = $this->GetServerVariable($arNode['DB'], 'auto_increment_offset');
			$mod = $offset % $auto_increment_increment;

			if (array_key_exists($mod, $auto_increment_offset))
			{
				$bIncIsOK = false;
				if ($node_id == 'v99')
				{
					$result[$node_id . '_auto_increment_offset'] = [
						'IS_OK' => CClusterDBNodeCheck::ERROR,
						'MESSAGE' => GetMessage('CLU_AUTO_INCREMENT_OFFSET_NODE_ERR_MSG', ['#current#' => $offset]),
						'WIZ_REC' => GetMessage('CLU_AUTO_INCREMENT_OFFSET_WIZREC', ['#current#' => $offset]),
					];
				}
				else
				{
					$result[$node_id . '_auto_increment_offset'] = [
						'IS_OK' => CClusterDBNodeCheck::ERROR,
						'MESSAGE' => GetMessage('CLU_AUTO_INCREMENT_OFFSET_ERR_MSG', ['#node_id#' => $node_id, '#current#' => $offset]),
						'WIZ_REC' => GetMessage('CLU_AUTO_INCREMENT_OFFSET_WIZREC', ['#current#' => $offset]),
					];
				}
			}
			else
			{
				$auto_increment_offset[$mod] = $node_id;
			}
		}
		if ($bIncIsOK)
		{
			$result['_auto_increment_offset'] = [
				'IS_OK' => CClusterDBNodeCheck::OK,
				'MESSAGE' => GetMessage('CLU_AUTO_INCREMENT_OFFSET_OK_MSG', ['#value#' => $auto_increment_increment]),
				'WIZ_REC' => '',
			];
		}

		$bRelayIsOK = true;
		foreach ($arMasters as $node_id => $arNode)
		{
			$relay_log = $this->GetServerVariable($arNode['DB'], 'relay_log');
			if ($relay_log == '')
			{
				$bIncIsOK = false;
				$result[$node_id . '_relay_log'] = [
					'IS_OK' => CClusterDBNodeCheck::ERROR,
					'MESSAGE' => GetMessage('CLU_RELAY_LOG_ERR_MSG', ['#node_id#' => $node_id, '#relay-log#' => $relay_log]),
					'WIZ_REC' => GetMessage('CLU_RELAY_LOG_WIZREC'),
				];
			}
		}
		if ($bRelayIsOK)
		{
			$result['_relay_log'] = [
				'IS_OK' => CClusterDBNodeCheck::OK,
				'MESSAGE' => GetMessage('CLU_RELAY_LOG_OK_MSG', ['#value#' => $auto_increment_increment]),
				'WIZ_REC' => '',
			];
		}

		$log_bin = $this->GetServerVariable($nodeDB, 'log_bin');
		$is_ok = $log_bin === 'ON';
		$result['log_bin'] = [
			'IS_OK' => $is_ok ? CClusterDBNodeCheck::OK : CClusterDBNodeCheck::ERROR,
			'MESSAGE' => GetMessage('CLU_LOG_BIN_NODE_MSG', ['#log-bin#' => $log_bin]),
			'WIZ_REC' => GetMessage('CLU_LOG_BIN_WIZREC'),
		];

		$skip_networking = $this->GetServerVariable($nodeDB, 'skip_networking');
		$is_ok = $skip_networking === 'OFF';
		$result['skip_networking'] = [
			'IS_OK' => $is_ok ? CClusterDBNodeCheck::OK : CClusterDBNodeCheck::ERROR,
			'MESSAGE' => GetMessage('CLU_SKIP_NETWORKING_NODE_MSG', ['#skip-networking#' => $skip_networking]),
			'WIZ_REC' => GetMessage('CLU_SKIP_NETWORKING_WIZREC'),
		];

		$innodb_flush_log_at_trx_commit = $this->GetServerVariable($nodeDB, 'innodb_flush_log_at_trx_commit');
		//if($innodb_flush_log_at_trx_commit !== '1')
		{
			$is_ok = $innodb_flush_log_at_trx_commit === '1';
			$result['innodb_flush_log_at_trx_commit'] = [
				'IS_OK' => $is_ok ? CClusterDBNodeCheck::OK : CClusterDBNodeCheck::WARNING,
				'MESSAGE' => GetMessage('CLU_FLUSH_ON_COMMIT_MSG', ['#innodb_flush_log_at_trx_commit#' => $innodb_flush_log_at_trx_commit]),
				'WIZ_REC' => '',
			];
		}

		$sync_binlog = $this->GetServerVariable($nodeDB, 'sync_binlog');
		//if($sync_binlog !== '1')
		{
			$is_ok = $sync_binlog === '1';
			$result['sync_binlog'] = [
				'IS_OK' => $is_ok ? CClusterDBNodeCheck::OK : CClusterDBNodeCheck::WARNING,
				'MESSAGE' => GetMessage('CLU_SYNC_BINLOG_MSG', ['#sync_binlog#' => $sync_binlog]),
				'WIZ_REC' => '',
			];
		}

		$DatabaseName = $DB->DBName;
		$is_ok = false;
		$rsBinLogs = $nodeDB->Query('show master status', true, '', ['fixed_connection' => true]);
		if (!$rsBinLogs)
		{
			$result['master_status'] = [
				'IS_OK' => CClusterDBNodeCheck::ERROR,
				'MESSAGE' => GetMessage('CLU_MASTER_STATUS_MSG'),
				'WIZ_REC' => GetMessage('CLU_MASTER_STATUS_WIZREC', ['#sql#' => "GRANT REPLICATION CLIENT on *.* to '" . $nodeDB->DBLogin . "'@'%';"]),
			];
		}
		else
		{
			if ($ar = $rsBinLogs->Fetch())
			{
				$Binlog_Do_DB = implode(',', array_keys(array_flip(explode(',', $ar['Binlog_Do_DB']))));
				if ($Binlog_Do_DB === $DatabaseName)
				{
					$is_ok = true;
				}
			}
			if ($rsBinLogs->Fetch())
			{
				$is_ok = false;
			}

			$result['binlog_do_db'] = [
				'IS_OK' => $is_ok ? CClusterDBNodeCheck::OK : CClusterDBNodeCheck::ERROR,
				'MESSAGE' => GetMessage('CLU_SYNC_BINLOGDODB_MSG'),
				'WIZ_REC' => GetMessage('CLU_SYNC_BINLOGDODB_WIZREC', ['#database#' => $DatabaseName]),
			];
		}

		return $result;
	}

	public static function GetServerVariables($DB, $arVariables, $db_mask)
	{
		if ($db_mask)
		{
			$where = " like '" . $DB->ForSql($db_mask) . "'";
		}
		else
		{
			$where = '';
		}

		$rs = $DB->Query('show variables ' . $where, false, '', ['fixed_connection' => true]);
		while ($ar = $rs->Fetch())
		{
			if (array_key_exists($ar['Variable_name'], $arVariables))
			{
				$arVariables[$ar['Variable_name']] = $ar['Value'];
			}
		}

		return $arVariables;
	}

	public function GetServerVariable($DB, $var_name)
	{
		$arResult = CClusterDBNodeCheck::GetServerVariables($DB, [$var_name => ''], $var_name);
		return $arResult[$var_name];
	}
}
