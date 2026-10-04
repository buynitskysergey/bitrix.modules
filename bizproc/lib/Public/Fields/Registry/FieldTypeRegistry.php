<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Fields\Registry;

final class FieldTypeRegistry
{
	private static bool $loaded = false;

	/** @var array<string, string> type -> extension name */
	private static array $typeMap = [];

	/** @var list<string> unique, stable-ordered extension names */
	private static array $extensions = [];

	/**
	 * Returns a flat list of unique JS extension names collected from all modules.
	 *
	 * @return list<string>
	 */
	public static function getExtensions(): array
	{
		self::load();

		return self::$extensions;
	}

	/**
	 * Returns a map of type identifier -> JS extension name.
	 * On collision the first declaration wins; duplicates are ignored.
	 *
	 * @return array<string, string>
	 */
	public static function getTypeMap(): array
	{
		self::load();

		return self::$typeMap;
	}

	/**
	 * Aggregates raw per-module configs into the internal state.
	 * Extracted for testability - call with a fixture array to test without ModuleManager.
	 *
	 * @param list<array{types?: list<array{type: string, extension: string}>, extensions?: list<string>}> $perModuleConfigs
	 */
	protected static function aggregate(array $perModuleConfigs): void
	{
		$extensionsSeen = [];

		foreach ($perModuleConfigs as $config)
		{
			$types = $config['types'] ?? null;
			if (is_array($types))
			{
				foreach ($types as $entry)
				{
					if (!is_array($entry))
					{
						continue;
					}

					$type = $entry['type'] ?? null;
					$extension = $entry['extension'] ?? null;

					if (empty($type) || !is_string($type) || empty($extension) || !is_string($extension))
					{
						continue;
					}

					// First declaration of a type wins.
					if (!array_key_exists($type, self::$typeMap))
					{
						self::$typeMap[$type] = $extension;
					}

					if (!isset($extensionsSeen[$extension]))
					{
						$extensionsSeen[$extension] = true;
						self::$extensions[] = $extension;
					}
				}
			}

			$extensions = $config['extensions'] ?? [];
			if (is_array($extensions))
			{
				foreach ($extensions as $extension)
				{
					if (empty($extension) || !is_string($extension))
					{
						continue;
					}

					if (!isset($extensionsSeen[$extension]))
					{
						$extensionsSeen[$extension] = true;
						self::$extensions[] = $extension;
					}
				}
			}
		}
	}

	private static function load(): void
	{
		if (self::$loaded)
		{
			return;
		}

		$perModuleConfigs = [];

		foreach (\Bitrix\Main\ModuleManager::getInstalledModules() as $moduleId => $moduleDesc)
		{
			$settings = \Bitrix\Main\Config\Configuration::getInstance($moduleId)->get('bizproc.field-types');
			if (!is_array($settings))
			{
				continue;
			}

			$perModuleConfigs[] = $settings;
		}

		self::aggregate($perModuleConfigs);

		self::$loaded = true;
	}
}
