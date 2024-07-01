<?php

use Bitrix\Main\Loader;

class CPHPCacheMemcacheCluster extends \Bitrix\Main\Data\CacheEngineMemcache
{
	private bool $bQueue = false;
	private static array $servers = [];
	private static array $otherGroups = [];

	public static function LoadConfig() : array
	{
		static $firstExec = true;
		if ($firstExec)
		{
			$arList = false;
			$firstExec = false;

			if (file_exists($_SERVER['DOCUMENT_ROOT'] . BX_ROOT . '/modules/cluster/memcache.php'))
			{
				include $_SERVER['DOCUMENT_ROOT'] . BX_ROOT . '/modules/cluster/memcache.php';
			}

			if (defined('BX_MEMCACHE_CLUSTER') && is_array($arList))
			{
				foreach ($arList as $server)
				{
					if ($server['STATUS'] !== 'ONLINE')
					{
						continue;
					}

					if (defined('BX_CLUSTER_GROUP') && ($server['GROUP_ID'] !== constant('BX_CLUSTER_GROUP')))
					{
						self::$otherGroups[$server['GROUP_ID']] = true;
						continue;
					}

					self::$servers[] = [
						'host' => $server['HOST'],
						'port' => $server['PORT'],
						'weight' => $server['WEIGHT']
					];
				}
			}
			else
			{
				self::$servers = [];
			}
		}

		return self::$servers;
	}

	public function __construct($options = [])
	{
		parent::__construct([
			'servers' => static::LoadConfig(),
			'type' => 'memcache'
		]);

		if (defined('BX_CLUSTER_GROUP'))
		{
			$this->bQueue = true;
		}

		$this->sid = BX_MEMCACHE_CLUSTER . $this->sid;
	}

	public function QueueRun($param1, $param2, $param3)
	{
		$this->bQueue = false;
		$this->clean($param1, $param2, $param3);
	}

	public function clean($baseDir, $initDir = false, $filename = false)
	{
		if ($this->isAvailable())
		{
			if ($this->bQueue && Loader::includeModule('cluster'))
			{
				foreach (self::$otherGroups as $group_id => $_)
				{
					CClusterQueue::Add($group_id, 'CPHPCacheMemcacheCluster', $baseDir, $initDir, $filename);
				}
			}

			parent::clean($baseDir, $initDir, $filename);
		}
	}
}
