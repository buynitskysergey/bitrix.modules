<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Public\Service\CustomTemplate;

use Bitrix\Main\Config\Configuration;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;
use Bitrix\MessageService\Public\Provider\CustomTemplate\Zone\AbstractZoneProvider;

/**
 * Registry of zone providers for custom message templates.
 *
 * Each zone (e.g. CRM, booking, calendar) is registered via the
 * `messageservice.custom-template` section of a module's `.settings.php`:
 *
 * ```
 * 'messageservice.custom-template' => [
 *     'zones' => [
 *         'crm' => ['provider' => CrmZoneProvider::class],
 *     ],
 * ],
 * ```
 *
 * Intended to be resolved as a singleton via ServiceLocator with
 * {@see self::load()} as factory. Lookups return `null` for unknown zones.
 */
final class CustomTemplateZoneRegistry
{
	/**
	 * @param array<string, AbstractZoneProvider> $providers Keyed by zone id.
	 */
	public function __construct(private readonly array $providers)
	{
	}

	public static function load(): self
	{
		$providers = [];
		foreach (ModuleManager::getInstalledModules() as $module)
		{
			$moduleId = is_array($module) ? ($module['ID'] ?? null) : $module;
			if (!is_string($moduleId) || $moduleId === '')
			{
				continue;
			}

			$config = Configuration::getInstance($moduleId)->get('messageservice.custom-template');
			if (!is_array($config))
			{
				continue;
			}

			$zones = $config['zones'] ?? null;
			if (!is_array($zones))
			{
				continue;
			}

			if (!Loader::includeModule($moduleId))
			{
				continue;
			}

			foreach ($zones as $zoneId => $zoneConfig)
			{
				$class = is_array($zoneConfig) ? ($zoneConfig['provider'] ?? null) : null;
				if (!is_string($class) || !class_exists($class) || !is_a($class, AbstractZoneProvider::class, true))
				{
					continue;
				}

				$providers[(string)$zoneId] = ServiceLocator::getInstance()->get($class);
			}
		}

		return new self($providers);
	}

	public function get(string $zoneId): ?AbstractZoneProvider
	{
		if ($zoneId === '')
		{
			return null;
		}

		return $this->providers[$zoneId] ?? null;
	}
}
