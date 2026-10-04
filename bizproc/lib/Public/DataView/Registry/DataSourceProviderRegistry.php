<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\DataView\Registry;

use Bitrix\Bizproc\Public\DataView\Interface\DataSourceProvider;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;

final class DataSourceProviderRegistry
{
	private const NATIVE_MODULE = 'bizproc';

	private const NATIVE_SERVICE = 'bizproc.service.dataView.nativeProvider';

	private const CONVENTION_CLASS = 'Bitrix\\%s\\Integration\\BizProc\\DataSource\\DataSourceProvider';

	private const CONVENTION_FILE = '/lib/integration/bizproc/datasource/datasourceprovider.php';

	/** @var array<string, DataSourceProvider|null> */
	private array $providersByModule = [];

	/** @var string[]|null */
	private ?array $discoveredModules = null;

	/** Providers are keyed by this form, so every module id compared with them must go through it. */
	public static function normalizeModuleId(string $moduleId): string
	{
		return mb_strtolower($moduleId);
	}

	public function register(string $moduleId, DataSourceProvider $provider): void
	{
		$this->providersByModule[self::normalizeModuleId($moduleId)] = $provider;
	}

	public function get(string $moduleId): ?DataSourceProvider
	{
		$module = self::normalizeModuleId($moduleId);
		if ($module === '')
		{
			return null;
		}

		if (!array_key_exists($module, $this->providersByModule))
		{
			$this->providersByModule[$module] = $module === self::NATIVE_MODULE
				? $this->resolveNativeProvider()
				: $this->loadProvider($module)
			;
		}

		return $this->providersByModule[$module];
	}

	/**
	 * @return array<string, DataSourceProvider>
	 */
	public function getAvailableProviders(): array
	{
		$modules = $this->discoverModules();
		foreach ($this->providersByModule as $module => $provider)
		{
			if ($provider !== null && !in_array($module, $modules, true))
			{
				$modules[] = $module;
			}
		}

		$providers = [];
		foreach ($modules as $module)
		{
			$provider = $this->get($module);
			if ($provider !== null)
			{
				$providers[$module] = $provider;
			}
		}

		return $providers;
	}

	/**
	 * @return string[]
	 */
	private function discoverModules(): array
	{
		if ($this->discoveredModules !== null)
		{
			return $this->discoveredModules;
		}

		$modules = [];
		foreach (ModuleManager::getInstalledModules() as $installed)
		{
			$module = self::normalizeModuleId((string)($installed['ID'] ?? ''));
			if ($module === '' || $module === self::NATIVE_MODULE)
			{
				continue;
			}

			if (Loader::getLocal('modules/' . $module . self::CONVENTION_FILE) !== false)
			{
				$modules[$module] = true;
			}
		}

		$modules = array_keys($modules);
		sort($modules);

		return $this->discoveredModules = array_merge([self::NATIVE_MODULE], $modules);
	}

	private function resolveNativeProvider(): ?DataSourceProvider
	{
		$locator = ServiceLocator::getInstance();
		if (!$locator->has(self::NATIVE_SERVICE))
		{
			return null;
		}

		$provider = $locator->get(self::NATIVE_SERVICE);

		return $provider instanceof DataSourceProvider ? $provider : null;
	}

	private function loadProvider(string $module): ?DataSourceProvider
	{
		if (!ModuleManager::isValidModule($module))
		{
			return null;
		}

		$className = sprintf(self::CONVENTION_CLASS, ucfirst($module));

		if (!Loader::includeModule($module) || !class_exists($className))
		{
			return null;
		}

		$instance = new $className();

		return $instance instanceof DataSourceProvider ? $instance : null;
	}
}
