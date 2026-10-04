<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AI\Agent\Generator\AgentConfig;

final readonly class FlowConfig
{
	/** Flow-level keys the format defines - the closed list of the level (TPL-05). */
	private const KEYS = ['trigger', 'trigger_props', 'steps', 'fanout'];

	public function __construct(
		public string $name,
		public string $trigger,
		public ?string $triggerId = null,
		public array $triggerProps = [],
		/** @var StepConfig[] */
		public array $steps = [],
		public ?ActivityDescriptorConfig $triggerNodeDescriptor = null,
		/** @var StepConfig[][] branches leaving the trigger output, order significant */
		public array $fanout = [],
		/**
		 * Keys of the trigger activity beside the ones the format states otherwise, as 'trigger_props' writes
		 * them down in '_activity'; null where the flow states none.
		 *
		 * @var array<string, mixed>|null
		 */
		public ?array $triggerActivityTail = null,
	) {}

	public static function fromArray(string $name, array $data): self
	{
		AgentConfig::assertKnownKeys("Flow '$name'", $data, self::KEYS);

		$trigger = $data['trigger'] ?? null;
		if (!is_string($trigger) || $trigger === '')
		{
			throw new \InvalidArgumentException("Flow '$name' must have a string 'trigger'");
		}

		$isFanout = array_key_exists('fanout', $data);
		if ($isFanout && array_key_exists('steps', $data))
		{
			throw new \InvalidArgumentException(
				"Flow '$name': 'steps' and 'fanout' are mutually exclusive - the trigger output leads either to one chain of steps or to several branches",
			);
		}
		$fanout = $isFanout ? StepConfig::parseFanoutBranches("Flow '$name'", $data['fanout']) : [];

		// A flow leaves a key out where it states nothing, so an explicit null is a value here and refused with
		// the rest: it used to reach '??' and give a flow with no steps and a trigger with no properties.
		$rawSteps = array_key_exists('steps', $data) ? $data['steps'] : [];
		if (!is_array($rawSteps))
		{
			throw new \InvalidArgumentException(sprintf(
				"Flow '%s': 'steps' must be an array, got %s",
				$name,
				get_debug_type($rawSteps),
			));
		}
		$steps = array_map([StepConfig::class, 'fromMixed'], $rawSteps);

		$triggerProps = array_key_exists('trigger_props', $data) ? $data['trigger_props'] : [];
		if (!is_array($triggerProps))
		{
			throw new \InvalidArgumentException(sprintf(
				"Flow '%s': 'trigger_props' must be an array, got %s",
				$name,
				get_debug_type($triggerProps),
			));
		}
		// A trigger without a name leaves '_id' out, so an explicit null is a value here and no name at all.
		$triggerId = null;
		if (array_key_exists('_id', $triggerProps))
		{
			$triggerId = $triggerProps['_id'];
			if (!is_string($triggerId))
			{
				throw new \InvalidArgumentException(
					"Flow '$name': trigger _id must be a string, got " . get_debug_type($triggerId),
				);
			}
			if (!preg_match(StepConfig::ID_PATTERN, $triggerId))
			{
				throw new \InvalidArgumentException("Invalid trigger ID format: '$triggerId'. Expected: A followed by four numeric segments separated by '_' (each segment >= 4 digits)");
			}
		}
		// '_id', '_node' and '_activity' describe the trigger node, not its properties: all three are cut out
		// here, the same way StepConfig cuts them out of a step body.
		$triggerNodeDescriptor = array_key_exists('_node', $triggerProps)
			? ActivityDescriptorConfig::fromMixed("Flow '$name' trigger _node", $triggerProps['_node'])
			: null;
		$triggerActivityTail = array_key_exists(StepConfig::ACTIVITY_KEY, $triggerProps)
			? StepConfig::parseActivityTail("Flow '$name' trigger", $triggerProps[StepConfig::ACTIVITY_KEY])
			: null;
		unset($triggerProps['_id'], $triggerProps['_node'], $triggerProps[StepConfig::ACTIVITY_KEY]);

		return new self(
			name: $name,
			trigger: $trigger,
			triggerId: $triggerId,
			triggerProps: $triggerProps,
			steps: $steps,
			triggerNodeDescriptor: $triggerNodeDescriptor,
			fanout: $fanout,
			triggerActivityTail: $triggerActivityTail,
		);
	}
}
