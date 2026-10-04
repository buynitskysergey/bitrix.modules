<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AI\Agent\Generator\TemplateBuilder;

use Bitrix\Bizproc\Activity\Enum\ActivityNodeType;
use Bitrix\Bizproc\Activity\Enum\ActivityPortType;
use Bitrix\Bizproc\Public\Activity\BaseComplexActivity;
use Bitrix\Bizproc\Runtime\ActivitySearcher\Searcher;

final class ActivityRegistry
{
	private const DEFAULT_ICON = 'DEFAULT';
	private const DEFAULT_COLOR_INDEX = 0;
	private const DEFAULT_DIMENSIONS = ['width' => 200, 'height' => 48];

	public const CONDITION_ACTIVITY_TYPE = 'IfElseBranchActivity';
	public const FOREACH_ACTIVITY_TYPE = 'ForEachActivity';
	public const SETUP_ACTIVITY_TYPE = 'SetupTemplateActivity';

	private const TYPE_MAP = [
		'Condition' => self::CONDITION_ACTIVITY_TYPE,
		'ForEach' => self::FOREACH_ACTIVITY_TYPE,
	];

	/** @var array<string, array|null> Cached activity descriptions */
	private array $cache = [];

	public function resolveActivityType(string $configType): string
	{
		return self::TYPE_MAP[$configType] ?? $configType;
	}

	/** @return bool true if the activity has a body via i1 loopback (composite). */
	public function isComposite(string $activityType): bool
	{
		return $activityType === self::FOREACH_ACTIVITY_TYPE;
	}

	public function isTrigger(string $activityType): bool
	{
		$declaredTypes = $this->getDescription($activityType)['TYPE'] ?? null;

		// Only a description naming a category answers here. An empty TYPE names none - the shipped
		// ManualStartTrigger and CreateDocumentTrigger declare exactly that - so it is no verdict and the
		// code reaches the single recognition below instead of being called no trigger.
		if (is_array($declaredTypes) && $declaredTypes !== [])
		{
			return Searcher::declaresTriggerType($declaredTypes);
		}

		if (!$this->resolvesHere($activityType))
		{
			// Fail-open as the other getters: an activity whose module is absent from this portal resolves
			// to nothing, and reverse conversion would drop the whole flow of a system AI agent.
			return str_ends_with($activityType, 'Trigger');
		}

		return \CBPRuntime::getRuntime()->isTriggerActivity($activityType);
	}

	public function getNodeType(string $activityType): ActivityNodeType
	{
		if ($this->isTrigger($activityType))
		{
			return ActivityNodeType::TRIGGER;
		}

		$desc = $this->getDescription($activityType);
		$nodeType = ActivityNodeType::tryFrom($desc['NODE_TYPE'] ?? '');

		return match ($nodeType)
		{
			ActivityNodeType::TRIGGER, ActivityNodeType::COMPLEX, ActivityNodeType::OPERATORS => $nodeType,
			default => ActivityNodeType::SIMPLE,
		};
	}

	public function getIcon(string $activityType): string
	{
		$desc = $this->getDescription($activityType);

		return $desc['NODE_ICON'] ?? self::DEFAULT_ICON;
	}

	public function getColorIndex(string $activityType): int
	{
		$desc = $this->getDescription($activityType);

		return $desc['COLOR_INDEX'] ?? self::DEFAULT_COLOR_INDEX;
	}

	public function getDefaultPorts(string $activityType): array
	{
		$desc = $this->getDescription($activityType);
		$settings = $desc['NODE_SETTINGS'] ?? null;

		if (is_array($settings) && !empty($settings['ports']))
		{
			return $settings['ports'];
		}

		return $this->buildFallbackPorts($activityType);
	}

	public function getDefaultDimensions(string $activityType): array
	{
		$desc = $this->getDescription($activityType);
		$settings = $desc['NODE_SETTINGS'] ?? null;

		$dimensions = self::DEFAULT_DIMENSIONS;
		if (is_array($settings))
		{
			if (isset($settings['width']))
			{
				$dimensions['width'] = $settings['width'];
			}
			if (isset($settings['height']))
			{
				$dimensions['height'] = $settings['height'];
			}
		}

		return $dimensions;
	}

	public function isConfigurable(string $activityType): bool
	{
		$className = $this->getClassName($activityType);

		return $className !== null && is_subclass_of($className, \IBPConfigurableActivity::class);
	}

	public function isComplexWrapper(string $activityType): bool
	{
		$className = $this->getClassName($activityType);

		return $className !== null && is_subclass_of($className, BaseComplexActivity::class);
	}

	/**
	 * Returns the aux port ID if the activity defines one, null otherwise.
	 */
	public function getAuxPort(string $activityType): ?string
	{
		foreach ($this->getDefaultPorts($activityType) as $port)
		{
			if (($port['type'] ?? '') === 'aux')
			{
				return $port['id'];
			}
		}

		return null;
	}

	/**
	 * Whether the activity declares the port with this type. A port the activity does not declare at all
	 * belongs to no type and answers false - the ports of the description are the whole port contract of
	 * the activity. Fail-open as the other getters: an unresolved activity answers from its fallback ports.
	 */
	public function isPortOfType(string $activityType, string $portId, ActivityPortType $type): bool
	{
		foreach ($this->getDefaultPorts($activityType) as $port)
		{
			if (($port['id'] ?? null) === $portId)
			{
				return ($port['type'] ?? '') === $type->value;
			}
		}

		return false;
	}

	/**
	 * Returns the RETURN definitions from the activity description (id => ['TYPE' => ..., ...]),
	 * or null if the activity has no declared return properties.
	 */
	public function getReturnPropertyDefinitions(string $activityType): ?array
	{
		$desc = $this->getDescription($activityType);
		$return = $desc['RETURN'] ?? null;

		return is_array($return) && !empty($return) ? $return : null;
	}

	public function getDialogMap(string $activityType): ?array
	{
		$className = $this->getClassName($activityType);
		if ($className === null || !method_exists($className, 'getPropertiesDialogMap'))
		{
			return null;
		}

		try
		{
			$ref = new \ReflectionMethod($className, 'getPropertiesDialogMap');
			if (!$ref->isStatic())
			{
				return null;
			}

			return $className::getPropertiesDialogMap();
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	public function getPropertyNames(string $activityType): ?array
	{
		$className = $this->getClassName($activityType);
		if ($className === null)
		{
			return null;
		}

		$fields = $this->extractPropertyNamesFromConstructor($className);

		if (empty($fields))
		{
			$dialogMap = $this->getDialogMap($activityType);
			if ($dialogMap !== null)
			{
				foreach ($dialogMap as $field)
				{
					if (isset($field['FieldName']) && $field['FieldName'] !== '')
					{
						$fields[] = $field['FieldName'];
					}
				}
			}
		}

		return !empty($fields) ? $fields : null;
	}

	public function getSourcePath(string $activityType): ?string
	{
		$desc = $this->getDescription($activityType);

		return $desc['PATH_TO_ACTIVITY'] ?? null;
	}

	private function getClassName(string $activityType): ?string
	{
		$className = 'CBP' . $activityType;
		\CBPRuntime::getRuntime()->includeActivityFile($activityType);

		return class_exists($className) ? $className : null;
	}

	private function extractPropertyNamesFromConstructor(string $className): array
	{
		try
		{
			$instance = new $className('__registry_temp__');
			$ref = new \ReflectionProperty($className, 'arProperties');
			$props = $ref->getValue($instance);

			return is_array($props) ? array_keys($props) : [];
		}
		catch (\Throwable)
		{
			return [];
		}
	}

	public function getDisplayName(string $activityType): ?string
	{
		return $this->getDescription($activityType)['NAME'] ?? null;
	}

	/**
	 * Whether the activity resolves in this environment. The getters stay fail-open and substitute
	 * defaults for an unresolved activity; callers that must not ship invented values ask this first
	 * - see {@see StrictActivityResolver}.
	 */
	public function hasDescription(string $activityType): bool
	{
		return $this->getDescription($activityType) !== null;
	}

	/** Whether the activity resolves here at all: neither a description nor a class means it does not. */
	private function resolvesHere(string $activityType): bool
	{
		if ($this->getDescription($activityType) !== null)
		{
			return true;
		}

		try
		{
			return $this->getClassName($activityType) !== null;
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	private function getDescription(string $activityType): ?array
	{
		if (array_key_exists($activityType, $this->cache))
		{
			return $this->cache[$activityType];
		}

		$runtime = \CBPRuntime::getRuntime();

		$this->cache[$activityType] = $runtime->getActivityDescription($activityType);

		return $this->cache[$activityType];
	}

	private function buildFallbackPorts(string $activityType): array
	{
		$ports = [];

		if (!$this->isTrigger($activityType))
		{
			$ports[] = (new NodeInput())->toPortArray();
		}

		$ports[] = (new NodeOutput())->toPortArray();

		return $ports;
	}
}
