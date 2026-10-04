<?php

namespace Bitrix\Bizproc\Integration\AiAssistant;

use Bitrix\Bizproc\Activity\ActivityDescription;
use Bitrix\Bizproc\Activity\PropertiesDialog;
use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Internal\Entity\Activity\ActivityAiDescriptionSource;
use Bitrix\Bizproc\Internal\Entity\Activity\Result\ActivityAiDescriptionResult;
use Bitrix\Bizproc\Internal\Entity\Activity\Setting;
use Bitrix\Bizproc\Internal\Entity\Activity\SettingCollection;
use Bitrix\Bizproc\Internal\Service\Container;
use Bitrix\Bizproc\RestActivityTable;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Builds an AI settings schema for an activity from its own declared properties map.
 * Used as a fallback when the activity has no manual `.ai.php` description.
 */
class ActivityAiAutoDescriber
{
	public const ERROR_ACTIVITY_NOT_FOUND = 'AUTO_DESCRIPTION_ACTIVITY_NOT_FOUND';
	public const ERROR_DISABLED = 'AUTO_DESCRIPTION_DISABLED';
	public const ERROR_NOT_SUITABLE = 'AUTO_DESCRIPTION_NOT_SUITABLE';

	private const PROPERTIES_MAP_METHOD = 'getPropertiesMap';
	private const PROPERTIES_DIALOG_MAP_METHOD = 'getPropertiesDialogMap';
	private const SERVICE_PROPERTIES = ['Title', 'EditorComment'];

	/**
	 * Builds an AI settings schema for a REST activity from its RestActivityTable entry.
	 * Non-REST activity codes are not supported; use {@see buildMapSettingsLenient()} instead.
	 *
	 * @see buildMapSettingsLenient() for the lenient file-based path used by the gate.
	 */
	public function describe(string $code, array $documentType): Result|ActivityAiDescriptionResult
	{
		if ($this->isRestActivityCode($code))
		{
			return $this->describeRestActivity($code, $documentType);
		}

		// Non-REST activities use the lenient path via the gate (buildMapSettingsLenient).
		// The strict describe() path is intentionally limited to REST activity codes.
		return (new Result())->addError(
			new Error('describe() is only supported for REST activity codes', self::ERROR_NOT_SUITABLE)
		);
	}

	/**
	 * Builds a lenient settings map from the activity's declared properties map.
	 * Unlike the strict (REST) {@see describe()} path, this method never returns NOT_SUITABLE:
	 * - required properties that cannot be expressed go to $skipped rather than aborting;
	 * - an empty map, a disabled activity, or a REST code yields [empty collection, []].
	 *
	 * @param string $code Activity code.
	 * @param array $documentType ['module', 'entityType', 'documentType'].
	 *
	 * @return array{0: SettingCollection, 1: list<string>} [$settings, $skippedPropertyNames]
	 */
	public function buildMapSettingsLenient(string $code, array $documentType): array
	{
		if ($this->isRestActivityCode($code))
		{
			return [new SettingCollection(), []];
		}

		$activityDescription = $this->findActivityDescription($code);
		if ($activityDescription === null)
		{
			return [new SettingCollection(), []];
		}

		if (!$activityDescription->isAiAutoDescriptionAllowed())
		{
			return [new SettingCollection(), []];
		}

		$map = $this->getDeclaredPropertiesMap($code, $activityDescription, $documentType);
		$map = array_diff_key($map, array_flip(self::SERVICE_PROPERTIES));

		if (!$map)
		{
			return [new SettingCollection(), []];
		}

		return $this->buildSettingsResultLenient($map, $documentType);
	}

	/**
	 * Lenient variant of {@see buildSettingsResult()}: unresolvable properties (required or not)
	 * are collected into $skipped instead of aborting with NOT_SUITABLE.
	 *
	 * @return array{0: SettingCollection, 1: list<string>}
	 */
	private function buildSettingsResultLenient(array $map, array $documentType): array
	{
		$converter = new ActivityAiPropertyConverter();
		$settings = new SettingCollection();
		$skipped = [];

		foreach ($map as $name => $field)
		{
			$name = (string)$name;
			$setting = $this->convertProperty($converter, $name, $field, $documentType);
			if ($setting !== null)
			{
				$settings->add($setting);
			}
			else
			{
				$skipped[] = $name;
			}
		}

		return [$settings, $skipped];
	}

	private function findActivityDescription(string $code): ?ActivityDescription
	{
		return Container::instance()->getActivitySearcherService()->searchByCode($code);
	}

	private function getDeclaredPropertiesMap(
		string $code,
		ActivityDescription $activityDescription,
		array $documentType
	): array
	{
		// Pass an empty $context (second arg) so activities that read current-values context
		// (e.g. to add conditional fields) return their full schema instead of a trimmed one.
		// An empty array matches what the designer passes on first open (no current values set).
		$map = $this->callActivityStaticMethod($code, self::PROPERTIES_MAP_METHOD, [$documentType, []]);
		if (!is_array($map) || !$map)
		{
			$dialog = new PropertiesDialog(
				$this->resolveActivityFilePath($code, $activityDescription),
				['documentType' => $documentType]
			);
			$rawDialogMap = $this->callActivityStaticMethod($code, self::PROPERTIES_DIALOG_MAP_METHOD, [$dialog]);
			// Dialog map is a list of field descriptors; convert to a properties map keyed by FieldName.
			$map = $this->reindexDialogMap($rawDialogMap);
		}

		if (!is_array($map))
		{
			return [];
		}

		return array_filter($map, 'is_array');
	}

	/**
	 * Filters a dialog map returned by `getPropertiesDialogMap()` to a clean properties map.
	 *
	 * `getPropertiesDialogMap()` already returns an ASSOCIATIVE array whose **keys are property names**
	 * (PascalCase, e.g. `Users`, `Name`, `AccessType`). This is the canonical `Setting.name` used
	 * everywhere else - it matches the keys of `getPropertiesMap()` and the keys in `.ai.php`.
	 *
	 * `FieldName` inside each descriptor is the HTML form-control name (snake_case, e.g.
	 * `requested_users`) - it is NOT the property name and must NOT be used as the map key.
	 *
	 * Numeric or empty-string keys indicate synthetic UI-only entries (e.g. section headers) and
	 * are dropped. Non-array values are likewise dropped.
	 *
	 * @param mixed $rawMap Return value of `getPropertiesDialogMap()`.
	 * @return array<string, array>|null Keyed by property name (map key), or null if $rawMap is not an array.
	 */
	private function reindexDialogMap(mixed $rawMap): ?array
	{
		if (!is_array($rawMap))
		{
			return null;
		}

		$map = [];
		foreach ($rawMap as $key => $field)
		{
			if (!is_string($key) || $key === '' || !is_array($field))
			{
				// Numeric-keyed or non-array entries are synthetic/UI-only - skip.
				continue;
			}

			$map[$key] = $field;
		}

		return $map ?: null;
	}

	private function callActivityStaticMethod(string $code, string $method, array $parameters): mixed
	{
		try
		{
			// without the included activity file callStaticMethod() returns an error-shaped array,
			// which the caller could mistake for a valid properties map
			if (!\CBPRuntime::getRuntime()->includeActivityFile($code))
			{
				return null;
			}

			return \CBPActivity::callStaticMethod($code, $method, $parameters);
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	/**
	 * Builds a strict settings schema for a REST activity from its RestActivityTable entry.
	 *
	 * Intentional asymmetry with the file-based lenient path (buildMapSettingsLenient):
	 * REST properties come from a machine-readable database record (RestActivityTable.PROPERTIES)
	 * rather than a PHP file. A required property whose type cannot be expressed means the DB record
	 * is malformed - returning a partial schema would silently hide data loss. For REST activities
	 * the strict NOT_SUITABLE response is therefore preferable to partial/lenient acceptance.
	 * File-based activities tolerate partial schemas because their property maps are richer and
	 * partial coverage is a normal authoring reality (not a data-integrity violation).
	 */
	private function describeRestActivity(string $code, array $documentType): Result|ActivityAiDescriptionResult
	{
		$normalizedCode = $this->normalizeActivityCode($code);
		$internalCode = mb_substr($normalizedCode, mb_strlen(\CBPRuntime::REST_ACTIVITY_PREFIX));

		$queryResult = RestActivityTable::getList([
			'filter' => ['=INTERNAL_CODE' => $internalCode],
			// Only PROPERTIES are selected; RETURN_PROPERTIES are intentionally excluded
			// from the settings schema - they are output fields, not inputs the AI agent sets.
			'select' => ['PROPERTIES'],
			'cache' => ['ttl' => 3600],
			'limit' => 1,
		]);

		$row = $queryResult->fetch();
		if ($row === false)
		{
			return (new Result())->addError(
				new Error('REST activity not found in database', self::ERROR_ACTIVITY_NOT_FOUND)
			);
		}

		$rawProperties = is_array($row['PROPERTIES']) ? $row['PROPERTIES'] : [];

		$map = [];
		$langId = defined('LANGUAGE_ID') ? LANGUAGE_ID : 'EN';
		foreach ($rawProperties as $name => $property)
		{
			if (!is_array($property))
			{
				continue;
			}

			if (isset($property['NAME']) && is_array($property['NAME']))
			{
				$property['NAME'] = RestActivityTable::getLocalization($property['NAME'], $langId);
			}

			if (isset($property['DESCRIPTION']) && is_array($property['DESCRIPTION']))
			{
				$property['DESCRIPTION'] = RestActivityTable::getLocalization($property['DESCRIPTION'], $langId);
			}

			$map[(string)$name] = $property;
		}

		$map = array_diff_key($map, array_flip(self::SERVICE_PROPERTIES));

		return $this->buildSettingsResult($code, $map, $documentType);
	}

	private function buildSettingsResult(
		string $code,
		array $map,
		array $documentType,
	): Result|ActivityAiDescriptionResult
	{
		$converter = new ActivityAiPropertyConverter();
		$settings = new SettingCollection();
		$skippedSettings = [];

		foreach ($map as $name => $field)
		{
			$name = (string)$name;
			$setting = $this->convertProperty($converter, $name, $field, $documentType);
			if ($setting !== null)
			{
				$settings->add($setting);

				continue;
			}

			if ($this->isRequiredProperty($field))
			{
				return (new Result())->addError(new Error(
					"Required property '{$name}' cannot be expressed in the settings schema",
					self::ERROR_NOT_SUITABLE
				));
			}

			$skippedSettings[] = $name;
		}

		return new ActivityAiDescriptionResult(
			code: $code,
			settings: $settings,
			source: ActivityAiDescriptionSource::Auto,
			skippedSettings: $skippedSettings,
		);
	}

	private function convertProperty(
		ActivityAiPropertyConverter $converter,
		string $name,
		array $field,
		array $documentType
	): ?Setting
	{
		try
		{
			return $converter->convertMap([$name => $field], $documentType)->findFirstByName($name);
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	private function isRequiredProperty(array $field): bool
	{
		try
		{
			$normalized = FieldType::normalizeProperty($field);
		}
		catch (\Throwable)
		{
			return false;
		}

		return !empty($normalized['Required']);
	}

	private function resolveActivityFilePath(string $code, ActivityDescription $activityDescription): string
	{
		$path = $activityDescription->getPathToActivity();
		if ($path === '')
		{
			return '';
		}

		return $path . '/' . $this->normalizeActivityCode($code) . '.php';
	}

	private function normalizeActivityCode(string $code): string
	{
		$normalizedCode = mb_strtolower(trim($code));
		if (str_starts_with($normalizedCode, 'cbp'))
		{
			$normalizedCode = mb_substr($normalizedCode, 3);
		}

		return $normalizedCode;
	}

	public function isRestActivityCode(string $code): bool
	{
		return str_starts_with($this->normalizeActivityCode($code), \CBPRuntime::REST_ACTIVITY_PREFIX);
	}
}
