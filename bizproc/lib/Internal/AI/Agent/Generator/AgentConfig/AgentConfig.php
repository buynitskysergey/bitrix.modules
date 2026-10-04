<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AI\Agent\Generator\AgentConfig;

use Bitrix\Main\IO\File;

final class AgentConfig
{
	/** The closed list of the document level (TPL-05). */
	private const KEYS = [
		'name',
		'title',
		'description',
		'root_title',
		'wizard_title',
		'wizard_description',
		'parameters',
		'variables',
		'constants',
		'flows',
		'activity_descriptors',
		'_partial',
	];

	/** Sections written as a map, and what the map holds: the refusal says it, see assertSectionTypes(). */
	private const MAP_SECTIONS = [
		'parameters' => 'a map of parameter name to the field of that parameter',
		'variables' => 'a map of variable name to the field of that variable',
		'constants' => 'a map of constant name to constant',
		'flows' => 'a map of flow name to flow',
		'activity_descriptors' => 'a map of activity type to node descriptor',
	];

	/**
	 * Sections written as a flag. '_partial' is one the reverse writes, and it says the source describes its
	 * agent in part only - so a value of another shape is refused rather than read for its truth: '"_partial":
	 * "no"' used to mark the source partial, and '"_partial": null' used to unmark it.
	 */
	private const FLAG_SECTIONS = ['_partial'];

	/** Sections written as a string. */
	private const STRING_SECTIONS = [
		'name',
		'title',
		'description',
		'root_title',
		'wizard_title',
		'wizard_description',
	];

	public function __construct(
		public readonly string $name,
		public readonly string $title,
		public readonly string $description,
		/** @var array<string, ConstantConfig> */
		public readonly array $constants,
		/** @var array<string, FlowConfig> */
		public readonly array $flows,
		public readonly ?string $wizardTitle = null,
		public readonly ?string $wizardDescription = null,
		/**
		 * Lang key of the Title the root activity of the template carries - the activity the nodes hang in, not a
		 * node of the canvas. No key at all means the same key as the name of the agent, the way the build wrote
		 * it before the format could say otherwise.
		 */
		public readonly ?string $rootTitle = null,
		/** @var array<string, ActivityDescriptorConfig> activity type => node descriptor */
		public readonly array $activityDescriptors = [],
		/**
		 * Parameters and variables of the template, in the shape the template itself holds them: a map of the
		 * name to the field of that name (Name, Description, Type, Multiple, Required, Options, Settings,
		 * Default - \Bitrix\Bizproc\FieldType::normalizeProperty()). The build writes both sections as they
		 * stand, so neither is a level of the format with keys of its own.
		 *
		 * @var array<string, mixed>
		 */
		public readonly array $parameters = [],
		/** @var array<string, mixed> */
		public readonly array $variables = [],
		/** Marked by the reverse of a round trip that lost something, run with '--allow-lossy' (TPL-06). */
		public readonly bool $isPartial = false,
	) {}

	public static function fromFile(string $path): self
	{
		if (!File::isFileExists($path))
		{
			throw new \InvalidArgumentException("Config file not found: {$path}");
		}

		$content = File::getFileContents($path);
		if (!is_string($content))
		{
			throw new \InvalidArgumentException("Failed to read config: {$path}");
		}

		try
		{
			$data = \Bitrix\Main\Web\Json::decode($content);
		}
		catch (\Bitrix\Main\ArgumentException $e)
		{
			throw new \InvalidArgumentException("Invalid JSON in {$path}: {$e->getMessage()}");
		}

		if (!is_array($data))
		{
			throw new \InvalidArgumentException("Config must be a JSON object: {$path}");
		}

		return self::fromArray($data);
	}

	/**
	 * Refuses a key its level of the format does not parse. Dropping an unknown key silently is what
	 * the closed lists remove: the author of an agent used to learn about a misspelled or
	 * never-supported key only by the missing effect.
	 *
	 * @param array<string, mixed> $data
	 * @param list<string> $allowedKeys
	 */
	public static function assertKnownKeys(string $context, array $data, array $allowedKeys): void
	{
		$unknownKeys = array_diff(array_keys($data), $allowedKeys);
		if (!empty($unknownKeys))
		{
			throw new \InvalidArgumentException(sprintf(
				'%s: unknown key(s) [%s]. Allowed: %s',
				$context,
				implode(', ', $unknownKeys),
				implode(', ', $allowedKeys),
			));
		}
	}

	public static function fromArray(array $data): self
	{
		self::assertKnownKeys('Agent config', $data, self::KEYS);
		self::assertSectionTypes($data);

		$constants = [];
		foreach ($data['constants'] ?? [] as $key => $constData)
		{
			$constants[$key] = ConstantConfig::fromArray($key, $constData);
		}

		$flows = [];
		foreach ($data['flows'] ?? [] as $name => $flowData)
		{
			$flows[$name] = FlowConfig::fromArray($name, $flowData);
		}

		$activityDescriptors = [];
		foreach ($data['activity_descriptors'] ?? [] as $activityType => $descriptorData)
		{
			$activityDescriptors[$activityType] = ActivityDescriptorConfig::fromMixed(
				"Activity descriptor '$activityType'",
				$descriptorData,
			);
		}

		return new self(
			name: $data['name'] ?? '',
			title: $data['title'] ?? '',
			description: $data['description'] ?? '',
			constants: $constants,
			flows: $flows,
			wizardTitle: $data['wizard_title'] ?? null,
			wizardDescription: $data['wizard_description'] ?? null,
			rootTitle: $data['root_title'] ?? null,
			activityDescriptors: $activityDescriptors,
			parameters: $data['parameters'] ?? [],
			variables: $data['variables'] ?? [],
			isPartial: $data['_partial'] ?? false,
		);
	}

	/**
	 * Every node name the document states itself: the name of a trigger, of a step, and of the activity a complex
	 * wrapper runs inside a step - whether the step names it by '_inner_id' or writes out the rules that name it
	 * ({@see ComplexActivityRules}). The build claims them all before it hands out the first generated name, so that
	 * a step without an '_id' cannot be given the name a step further down the document states - the two would
	 * then be one node, and the build refuses the second of them (ActivityNodeBuilder::takeName()). The order the
	 * names are stated in is no part of the answer: a step may point forward, and the format says so ('_ref').
	 *
	 * @return list<string>
	 */
	public function declaredNodeIds(): array
	{
		$ids = [];

		foreach ($this->flows as $flow)
		{
			if ($flow->triggerId !== null)
			{
				$ids[] = $flow->triggerId;
			}

			foreach (self::getFlowSteps($flow) as $step)
			{
				array_push($ids, ...$step->declaredNodeIds());
			}
		}

		return $ids;
	}

	/**
	 * The steps of a flow, whether its trigger leads into one chain of them or into several branches.
	 *
	 * @return list<StepConfig>
	 */
	private static function getFlowSteps(FlowConfig $flow): array
	{
		return array_merge($flow->steps, ...$flow->fanout);
	}

	public function validate(): array
	{
		$errors = [];

		if (empty($this->name))
		{
			$errors[] = 'name is required';
		}

		if (empty($this->title))
		{
			$errors[] = 'title is required';
		}

		if (empty($this->flows))
		{
			$errors[] = 'at least one flow is required';
		}

		return $errors;
	}

	/**
	 * Refuses a section written as something the format never writes it as. A known key of a wrong type used to
	 * reach a foreach or the constructor, and the author of an agent was answered by a warning of PHP or by a
	 * TypeError instead of by the format - the same silence the closed lists of keys removed.
	 *
	 * A section written as null is refused with the rest, the way a construct of a step is (StepConfig): the
	 * format tells the absence of a key from the value under it, so null is a value here and not a way of
	 * saying nothing.
	 *
	 * A map written as a list is refused too: '"parameters": [{"Type": "string"}]' used to reach PARAMETERS
	 * as it stands, and the field went into the template under the key '0' instead of under a name - a
	 * parameter nothing can address by name. Only a non-empty list is refused, because an empty JSON object
	 * and an empty JSON list are one and the same value after decoding, and an empty section is a form the
	 * format does write.
	 *
	 * @param array<string, mixed> $data
	 */
	private static function assertSectionTypes(array $data): void
	{
		foreach (self::MAP_SECTIONS as $key => $expected)
		{
			if (!array_key_exists($key, $data))
			{
				continue;
			}

			$section = $data[$key];
			if (!is_array($section) || ($section !== [] && array_is_list($section)))
			{
				throw new \InvalidArgumentException(sprintf(
					"'%s' must be %s, got %s",
					$key,
					$expected,
					is_array($section) ? 'a list' : get_debug_type($section),
				));
			}
		}

		foreach (self::STRING_SECTIONS as $key)
		{
			if (array_key_exists($key, $data) && !is_string($data[$key]))
			{
				throw new \InvalidArgumentException(sprintf(
					"'%s' must be a string, got %s",
					$key,
					get_debug_type($data[$key]),
				));
			}
		}

		foreach (self::FLAG_SECTIONS as $key)
		{
			if (array_key_exists($key, $data) && !is_bool($data[$key]))
			{
				throw new \InvalidArgumentException(sprintf(
					"'%s' must be a flag, got %s",
					$key,
					get_debug_type($data[$key]),
				));
			}
		}
	}
}
