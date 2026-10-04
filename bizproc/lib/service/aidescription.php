<?php

namespace Bitrix\Bizproc\Service;

use Bitrix\Bizproc\Integration\AiAssistant\ActivityAiAutoDescriber;
use Bitrix\Bizproc\Integration\AiAssistant\ActivityAiDescriptionMerger;
use Bitrix\Bizproc\Integration\AiAssistant\ActivityAiPropertyConverter;
use Bitrix\Bizproc\Integration\AiAssistant\Interface\IBPActivityAiDescription;
use Bitrix\Bizproc\Internal\Entity\Activity\ActivityAiDescriptionSource;
use Bitrix\Bizproc\Internal\Entity\Activity\Result\ActivityAiDescriptionResult;
use Bitrix\Bizproc\Internal\Entity\Activity\Setting;
use Bitrix\Bizproc\Internal\Entity\Activity\SettingCollection;
use Bitrix\Bizproc\Internal\Entity\Activity\SettingOption;
use Bitrix\Bizproc\Internal\Entity\Activity\SettingOptionCollection;
use Bitrix\Bizproc\Internal\Entity\Activity\SettingType;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class AiDescription extends \CBPRuntimeService
{
	public const ERROR_ACTIVITY_FILE_NOT_FOUND = 'AI_DESCRIPTION_ACTIVITY_FILE_NOT_FOUND';
	public const ERROR_CLASS_NOT_FOUND = 'AI_DESCRIPTION_CLASS_NOT_FOUND';
	public const ERROR_INTERFACE_NOT_IMPLEMENTED = 'AI_DESCRIPTION_INTERFACE_NOT_IMPLEMENTED';
	public const ERROR_BUILD_FAILED = 'AI_DESCRIPTION_BUILD_FAILED';

	private const LOGGER_ID = 'bizproc.aiassistant.activity_description';

	/** @var array<string, Result|ActivityAiDescriptionResult> */
	private array $activityDescriptionCache = [];

	private ?LoggerInterface $logger = null;

	/**
	 * Returns the AI settings schema of the activity. A manual description (`.ai.php` next to the activity)
	 * always takes priority; when it is absent, the schema is derived from the activity's own properties map.
	 * Exceptions of a single activity are isolated into an error Result so that one broken activity
	 * cannot break a whole catalog.
	 *
	 * @param string $code Activity code like delayactivity
	 * @param list<string> $documentType ['module', 'entityType', 'documentType']
	 *
	 * @return Result|ActivityAiDescriptionResult
	 */
	public function getActivityDescription(
		string $code,
		array $documentType
	): Result|ActivityAiDescriptionResult
	{
		$cacheKey = mb_strtolower($code) . '|' . implode(',', $documentType);
		if (!isset($this->activityDescriptionCache[$cacheKey]))
		{
			try
			{
				$result = $this->buildActivityDescription($code, $documentType);
			}
			catch (\Throwable $exception)
			{
				$this->getLogger()->error(
					'Activity AI description build failed',
					[
						'activity' => $code,
						'exception' => $exception,
					],
				);
				$result = (new Result())->addError(new Error(
					"Activity '{$code}' AI description build failed",
					self::ERROR_BUILD_FAILED
				));
			}

			$this->activityDescriptionCache[$cacheKey] = $result;
		}

		return $this->activityDescriptionCache[$cacheKey];
	}

	private function buildActivityDescription(
		string $code,
		array $documentType
	): Result|ActivityAiDescriptionResult
	{
		$runtime = \CBPRuntime::getRuntime();
		if (!$runtime->includeActivityFile($code))
		{
			return (new Result())->addError(new Error(
				'Activity class file not found',
				self::ERROR_ACTIVITY_FILE_NOT_FOUND
			));
		}

		if (!$runtime->includeActivityAiDescriptionFile($code))
		{
			$autoDescriber = new ActivityAiAutoDescriber();

			// REST path: buildMapSettingsLenient short-circuits to an empty schema for REST codes
			// (they have no PHP file to load a properties map from). Delegate to the full describe()
			// path which reads the real settings schema directly from RestActivityTable.
			if ($autoDescriber->isRestActivityCode($code))
			{
				return $autoDescriber->describe($code, $documentType);
			}

			// Lenient path: unresolvable/required properties go to $skipped instead of aborting.
			// This allows legacy file-based activities (NODE_TYPE=null) to get a partial schema
			// rather than being dropped via NOT_SUITABLE.
			[$mapSettings, $mapSkipped] = $autoDescriber->buildMapSettingsLenient(
				$code,
				$documentType,
			);

			return new ActivityAiDescriptionResult(
				code: $code,
				settings: $mapSettings,
				source: ActivityAiDescriptionSource::Auto,
				skippedSettings: $mapSkipped,
			);
		}

		$classname = $this->getActivityDescriptionClassName($code);
		if (!class_exists($classname))
		{
			return (new Result())->addError(new Error('Ai description class not found', self::ERROR_CLASS_NOT_FOUND));
		}

		$object = new $classname();
		if (!$object instanceof IBPActivityAiDescription)
		{
			return (new Result())->addError(new Error(
				'Ai description class not implements IBPActivityAiDescription interface',
				self::ERROR_INTERFACE_NOT_IMPLEMENTED
			));
		}

		// Hybrid orchestration: map = structural base, .ai.php = quality enrichment.
		try
		{
			$manual = $object->getAiDescribedSettings($documentType);
		}
		catch (\Throwable)
		{
			$manual = null;
		}

		try
		{
			[$mapSettings, $mapSkipped] = (new ActivityAiAutoDescriber())->buildMapSettingsLenient(
				$code,
				$documentType,
			);
		}
		catch (\Throwable)
		{
			$mapSettings = new SettingCollection();
			$mapSkipped = [];
		}

		// Stability: if both sources failed/empty -> return a build-failed error.
		if ($manual === null && $mapSettings->count() === 0)
		{
			return (new Result())->addError(new Error(
				"Activity '{$code}' AI description build failed",
				self::ERROR_BUILD_FAILED,
			));
		}

		// Manual-only: map is empty but .ai.php succeeded -> keep source=Manual.
		if ($mapSettings->count() === 0)
		{
			return new ActivityAiDescriptionResult(
				code: $code,
				settings: $manual,
				source: ActivityAiDescriptionSource::Manual,
			);
		}

		// Auto-only fallback: .ai.php failed but map is available -> use map with source=Auto.
		if ($manual === null)
		{
			return new ActivityAiDescriptionResult(
				code: $code,
				settings: $mapSettings,
				source: ActivityAiDescriptionSource::Auto,
				skippedSettings: $mapSkipped,
			);
		}

		// Hybrid: both sources available -> merge them.
		// mergeWithDropped() also returns names of map-only fields suppressed by G-opt (empty dynamic
		// selects). These names must be included in skippedSettings so that AgentSettingNameValidator
		// accepts them on round-trip and existing template values are not silently lost.
		[$merged, $gOptDropped] = (new ActivityAiDescriptionMerger())->mergeWithDropped($mapSettings, $manual);
		$mergedNames = [];
		foreach ($merged as $mergedSetting)
		{
			$mergedNames[] = $mergedSetting->name;
		}
		$skipped = array_values(array_diff($mapSkipped, $mergedNames));
		if ($gOptDropped)
		{
			$skipped = array_values(array_unique(array_merge($skipped, $gOptDropped)));
		}

		return new ActivityAiDescriptionResult(
			code: $code,
			settings: $merged,
			source: ActivityAiDescriptionSource::Hybrid,
			skippedSettings: $skipped,
		);
	}

	public function getActivityDescriptionClassName(string $code): string
	{
		return "CBPAI{$code}";
	}

	private function getLogger(): LoggerInterface
	{
		if ($this->logger === null)
		{
			$this->logger = (new LoggerFactory())->createById(self::LOGGER_ID) ?? new NullLogger();
		}

		return $this->logger;
	}

	public function getEditableDocumentFieldSettings(array $documentType): ?SettingCollection
	{
		$fields = \CBPRuntime::GetRuntime()
			->getDocumentService()
			->getDocumentFields($documentType)
		;

		if (!is_array($fields))
		{
			return null;
		}

		$settings = new SettingCollection();
		foreach ($fields as $id => $field)
		{
			if (empty($field['Editable']))
			{
				continue;
			}

			$settings->add(
				new Setting(
					name: $id,
					description: (string)($field['Name'] ?? ''),
					type: $this->getSettingType($field, $documentType),
					required: !empty($field['Required']),
					options: $this->getOptions($field),
				)
			);
		}

		return $settings;
	}

	protected function getOptions(array $field): ?SettingOptionCollection
	{
		if (empty($field['Options']) || ! is_array($field['Options']))
		{
			return null;
		}

		$options = new SettingOptionCollection();
		foreach ($field['Options'] as $key => $value)
		{
			$options->add(new SettingOption(id: (string)$key, name: (string)$value));
		}

		return $options;
	}

	private function getSettingType(array $field, array $documentType): SettingType|string
	{
		$converter = new ActivityAiPropertyConverter();
		$fieldType = $converter->getFieldType($field, $documentType);
		if (!$fieldType)
		{
			return (string)($field['BaseType'] ?? '');
		}

		return $converter->getSettingType($fieldType);
	}
}