<?php

use Bitrix\Main\Config\Option;
use Bitrix\Main\Localization\Loc;

IncludeModuleLangFile(__FILE__);

class CClusterRedis
{
	public static $systemConfigurationUpdate = null;
	private static $arList = false;

	public static function loadConfig() : array
	{
		if (self::$arList === false)
		{
			$arList = false;
			if (file_exists($_SERVER['DOCUMENT_ROOT'] . BX_ROOT . '/modules/cluster/redis.php'))
			{
				include $_SERVER['DOCUMENT_ROOT'] . BX_ROOT . '/modules/cluster/redis.php';
			}

			if (defined('BX_REDIS_CLUSTER') && is_array($arList))
			{
				self::$arList = $arList;
			}
			else
			{
				self::$arList = [];
			}
		}

		return self::$arList;
	}

	public static function saveConfig($servers) : void
	{
		self::$arList = false;
		$isOnline = false;

		$content = '<' . '?' . 'php' . "\n"
			. 'if (!defined(\'BX_REDIS_CLUSTER\')) define(\'BX_REDIS_CLUSTER\', \'' . EscapePHPString(CMain::GetServerUniqID()) . '\');'
			. "\n" . '$arList = [' . "\n";

		$groups = [];
		$defaultGroup = 1;
		$clusterGroups = CClusterGroup::GetList(['ID' => 'DESC']);
		while ($group = $clusterGroups->Fetch())
		{
			$defaultGroup = $groups[$group['ID']] = (int) $group['ID'];
		}

		foreach ($servers as $i => $server)
		{
			$isOnline |= ($server['STATUS'] == 'ONLINE');

			$groupID = (int) $server['GROUP_ID'];
			if (!array_key_exists($server['GROUP_ID'], $groups))
			{
				$groupID = $defaultGroup;
			}

			$content .= "\t" . intval($i) . " => [\n";
			$content .= "\t\t'ID' => " . EscapePHPString($server['ID']) . ",\n";
			$content .= "\t\t'GROUP_ID' => " . $groupID . ",\n";
			$content .= "\t\t'HOST' => '" . EscapePHPString($server['HOST']) . "',\n";
			$content .= "\t\t'PORT' => " . intval($server['PORT']) . ",\n";

			switch ($server['STATUS'])
			{
				case 'ONLINE':
					$content .= "\t\t'STATUS' => 'ONLINE',\n";
					break;
				case 'OFFLINE':
					$content .= "\t\t'STATUS' => 'OFFLINE',\n";
					break;
				default:
					$content .= "\t\t'STATUS' => 'READY',\n";
					break;
			}

			$content .= "\t\t'MODE' => '" . $server['MODE'] . "',\n";
			$content .= "\t\t'ROLE' => '" . $server['ROLE'] . "',\n";
			$content .= "\t],\n";
		}

		$content .= "];\n";

		$filename = $_SERVER['DOCUMENT_ROOT'] . BX_ROOT . '/modules/cluster/redis.php';
		file_put_contents($filename, $content);
		\Bitrix\Main\Application::resetAccelerator($filename);

		self::$systemConfigurationUpdate = null;
		$cache = \Bitrix\Main\Config\Configuration::getValue('cache');

		if ($isOnline)
		{
			if (
				!is_array($cache)
				|| !isset($cache['type'])
				|| !is_array($cache['type'])
				|| !isset($cache['type']['class_name'])
				|| !($cache['type']['class_name'] === 'CPHPCacheRedisCluster')
			)
			{
				\Bitrix\Main\Config\Configuration::setValue('cache', [
					'type' => [
						'class_name' => 'CPHPCacheRedisCluster',
						'extension' => 'redis',
						'required_file' => 'modules/cluster/classes/general/redis_cache.php',
					],
					'failover' => Option::get('cluster', 'failower_settings'),
					'timeout' => Option::get('cluster', 'redis_timeoit'),
					'read_timeout' => Option::get('cluster', 'redis_read_timeout'),
					'persistent' => (Option::get('cluster', 'redis_persistent') === 'Y'),
				]);
				self::$systemConfigurationUpdate = true;
			}
		}
		else
		{
			if (
				is_array($cache)
				&& isset($cache['type'])
				&& is_array($cache['type'])
				&& isset($cache['type']['class_name'])
				&& ($cache['type']['class_name'] === 'CPHPCacheRedisCluster')
			)
			{
				\Bitrix\Main\Config\Configuration::setValue('cache', null);
				self::$systemConfigurationUpdate = false;
			}
		}
	}

	public static function getList()
	{
		$res = new CDBResult;
		$res->InitFromArray(CClusterRedis::loadConfig());
		return $res;
	}

	public static function getServerList() : array
	{
		$result = [];
		foreach (CClusterRedis::loadConfig() as $data)
		{
			$host = $data['HOST'] === '127.0.0.1' || $data['HOST'] === 'localhost' ? '' : $data['HOST'];
			$result[] = [
				'ID' => $data['ID'],
				'GROUP_ID' => $data['GROUP_ID'],
				'SERVER_TYPE' => 'redis',
				'ROLE_ID' => '',
				'HOST' => $host,
				'DEDICATED' => 'Y',
				'EDIT_URL' => '/bitrix/admin/cluster_redis_edit.php?lang=' . LANGUAGE_ID . '&group_id=' . $data['GROUP_ID'] . '&ID=' . $data['ID'],
			];
		}
		return $result;
	}

	public static function getByID($id) : array
	{
		$result = [];
		$ar = CClusterRedis::loadConfig();

		if (isset($ar[$id]) && is_array($ar[$id]))
		{
			$result = $ar[$id];
		}

		return $result;
	}

	public function add($fields) : int
	{
		if (!$this->checkFields($fields, false))
		{
			return false;
		}

		$servers = CClusterRedis::loadConfig();

		$id = 1;
		if (!is_array($servers))
		{
			return false;
		}

		foreach ($servers as $server)
		{
			if ($server['ID'] >= $id)
			{
				$id = $server['ID'] + 1;
			}
		}

		$status = self::getStatus($fields);
		$servers[$id] = [
			'ID' => $id,
			'GROUP_ID' => (int) $fields['GROUP_ID'],
			'STATUS' => 'READY',
			'HOST' => $fields['HOST'],
			'PORT' => $fields['PORT'],
			'MODE' => mb_strtoupper($status['redis_mode']),
			'ROLE' => mb_strtoupper($status['role']),
		];

		CClusterRedis::saveConfig($servers);
		return $id;
	}

	public static function delete($id) : bool
	{
		$servers = CClusterRedis::loadConfig();
		if (array_key_exists($id, $servers))
		{
			unset($servers[$id]);
			CClusterRedis::saveConfig($servers);
		}
		return true;
	}

	public function update($serverID, $fields) : bool
	{
		if (!is_array($serverID))
		{
			$serverID = [ 0 => (int) $serverID];
		}

		$servers = CClusterRedis::loadConfig();
		foreach ($serverID as $id)
		{
			if (!array_key_exists($id, $servers))
			{
				return false;
			}

			$status = $this->checkFields($servers[$id]);
			if (empty($status) || $status['message'] !== null || intval($status['uptime_in_seconds']) <= 0)
			{
				return false;
			}

			$servers[$id] = [
				'ID' => $id,
				'GROUP_ID' => $servers[$id]['GROUP_ID'],
				'STATUS' => $fields['STATUS'] ?? $servers[$id]['STATUS'],
				'HOST' => $fields['HOST'] ?? $servers[$id]['HOST'],
				'PORT' => $fields['PORT'] ?? $servers[$id]['PORT'],
				'MODE' => mb_strtoupper($servers[$id]['MODE']),
				'ROLE' => mb_strtoupper($servers[$id]['ROLE'])
			];
		}

		CClusterRedis::saveConfig($servers);
		return true;
	}

	public function checkFields(&$fields) : array
	{
		global $APPLICATION;

		$error = [];
		$status = [];

		$fields['PORT'] = intval($fields['PORT']);
		if ($fields['PORT'] > 0 && isset($fields['HOST']))
		{
			$status = self::getStatus($fields);

			if ($status['message'] !== null)
			{
				$error[] = [
					'id' => $fields['HOST'],
					'text' => Loc:: getMessage('CLU_REDIS_CANNOT_CONNECT')
				];
			}
		}

		if (!empty($error))
		{
			$e = new CAdminException($error);
			$APPLICATION->ThrowException($e);
			return [];
		}

		return $status;
	}

	public static function pause($serverID) : void
	{

		$servers = CClusterRedis::loadConfig();

		if (!is_array($serverID))
		{
			$serverID = [0 => $serverID];
		}

		foreach ($serverID as $i => $key)
		{
			if (!isset($servers[$key]) || $servers[$key]['STATUS'] != 'ONLINE')
			{
				unset($serverID[$i]);
			}
		}

		if (!empty($serverID))
		{
			$ob = new CClusterRedis;
			$ob->update($serverID, ['STATUS' => 'READY']);
		}
	}

	public static function resume($serverID) : void
	{
		$servers = CClusterRedis::loadConfig();
		if (!is_array($serverID))
		{
			$serverID = [ 0 => $serverID];
		}

		foreach ($serverID as $i => $key)
		{
			if (!isset($servers[$key]) || $servers[$key]['STATUS'] != 'READY')
			{
				unset($serverID[$i]);
			}
		}

		if (!empty($serverID))
		{
			$ob = new CClusterRedis;
			$ob->update($serverID, ['STATUS' => 'ONLINE']);
		}
	}

	public static function getStatus($server) : array
	{
		$stats = [
			'message' => null,
			'redis_version' => null,
			'redis_mode' => null,
			'os' => null,
			'uptime_in_seconds' => null,
			'connected_clients' => null,
			'total_system_memory' => null,
			'used_memory' => null,
			'maxmemory' => null,
			'maxmemory_policy' => null,
			'mem_fragmentation_ratio' => null,
			'loading' => null,
			'keyspace_hits' => null,
			'keyspace_misses' => null,
			'evicted_keys' => null,
			'expired_keys' => null,
			'expired_stale_perc' => null,
			'used_cpu_sys' => null,
			'used_cpu_user' => null,
			'used_cpu_sys_children' => null,
			'used_cpu_user_children' => null,
			'role' => null,
			'cluster_enabled' => null,
			'connected_slaves' => null,
			'master_replid' => null,
			'master_replid2' => null,
			'master_repl_offset' => null,
			'slave_expires_tracked_keys' => null
		];

		if (is_array($server))
		{
			try
			{
				$redis = new \Redis();
				if (@$redis->connect($server['HOST'], $server['PORT']))
				{
					$info = $redis->info();
					foreach ($stats as $key => $_)
					{
						$stats[$key] = $info[$key];
					}
				}
			}
			catch (RedisException $e)
			{
				$stats['message'] = $e->getMessage();
			}
		}

		return $stats;
	}
}
