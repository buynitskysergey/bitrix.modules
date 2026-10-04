<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AI\Agent\Generator;

use Bitrix\Bizproc\Internal\AI\Agent\Generator\AgentConfig\AgentConfig;
use Bitrix\Bizproc\Internal\AI\Agent\Generator\AgentConfig\StepConfig;
use Bitrix\Bizproc\Internal\AI\Agent\Generator\TemplateBuilder\ActivityRegistry;

final class ConfigValidator
{
	private array $warnings = [];

	public function __construct(
		private readonly ActivityRegistry $registry,
	) {}

	/**
	 * Does not block generation — returns warnings only.
	 *
	 * @return string[]
	 */
	public function validate(AgentConfig $config): array
	{
		$this->warnings = [];

		foreach ($config->flows as $flow)
		{
			// Reserved keys are cut out by FlowConfig, so triggerProps hold activity properties only.
			$this->validateActivityProperties($flow->trigger, $flow->triggerProps, "{$flow->name} (trigger)");

			// The trigger output leads either into one chain of steps or into fanout branches.
			foreach (self::collectSteps(array_merge($flow->steps, ...$flow->fanout)) as $step)
			{
				$this->validateStep($step, $flow->name);
			}
		}

		return $this->warnings;
	}

	/**
	 * Every step of the flow, whatever construct it lies in: an activity is checked the same way wherever
	 * its step is written, and a flow described by 'fanout' has no steps of its own at the top level.
	 *
	 * @param StepConfig[] $steps
	 * @return StepConfig[]
	 */
	private static function collectSteps(array $steps): array
	{
		$collected = [];

		foreach ($steps as $step)
		{
			// A reference creates no node and carries no properties: the node it points at is declared,
			// and checked, where it stands.
			if ($step->refId !== null)
			{
				continue;
			}

			$collected[] = $step;
			foreach (self::chainsOf($step) as $chain)
			{
				$collected = array_merge($collected, self::collectSteps($chain));
			}
		}

		return $collected;
	}

	/**
	 * The chains of steps a step opens: the branches of a condition, the body of a composite step, one
	 * branch per output port ('branches') or several ('fanout'), and the nodes 'attachments' bind to the
	 * aux ports. A construct nests chains but never itself, so the walk is finite.
	 *
	 * @return StepConfig[][]
	 */
	private static function chainsOf(StepConfig $step): array
	{
		return [
			$step->trueBranch,
			$step->falseBranch,
			$step->childSteps,
			...array_values($step->branches),
			...array_merge([], ...array_values($step->fanout)),
			...array_values($step->attachments),
		];
	}

	private function validateStep(StepConfig $step, string $flowName): void
	{
		if ($step->isCondition)
		{
			// Only the branches of a condition carry activity properties: its own body is 'conditions',
			// and ConditionConfig parses it.
			return;
		}

		if ($step->isComposite)
		{
			$this->validateActivityProperties($step->type, $step->props, $flowName);

			return;
		}

		$resolvedType = $this->registry->resolveActivityType($step->type);
		if ($this->registry->isComplexWrapper($resolvedType))
		{
			if (!empty($step->props) && $step->innerType !== null)
			{
				$this->validateActivityProperties($step->innerType, $step->props, $flowName);
			}

			return;
		}

		if (!empty($step->props))
		{
			$this->validateActivityProperties($step->type, $step->props, $flowName);
		}
	}

	private function validateActivityProperties(string $activityType, array $configProps, string $context): void
	{
		$knownFields = $this->registry->getPropertyNames($activityType);
		if ($knownFields === null)
		{
			return;
		}

		$isConfigurable = $this->registry->isConfigurable($activityType);

		foreach ($configProps as $key => $value)
		{
			if (!in_array($key, $knownFields, true))
			{
				$suffix = $isConfigurable ? ' (activity has dynamic properties — may be valid)' : '';
				$this->warnings[] = "[{$context}] {$activityType}: unknown property \"{$key}\"{$suffix}";
			}
		}
	}
}
