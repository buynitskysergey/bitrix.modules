<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AI\Agent\Generator\AgentConfig;

/**
 * The 'Rules' of a complex wrapper as a step writes them out: a map 'port id => rule of that port', a port
 * rule carrying its cards, a card carrying its constructions, and the construction that runs an activity
 * carrying that whole activity in its expression. A step states them verbatim and the build writes them into
 * the template verbatim, so the shape is the one the designer wrote and not one of the format.
 *
 * Two sides of the generator ask questions about that shape: the parse asks which node names the document
 * states ({@see StepConfig::declaredNodeIds()}), the build asks which activities the wrapper runs and how its
 * output ports are titled (TemplateBuilder). The shape is known here alone, so the two cannot answer one
 * question differently - the reason the keys of an activity the format states otherwise live in one constant
 * too ({@see StepConfig::ACTIVITY_KEYS_EXPRESSED_OTHERWISE}).
 */
final class ComplexActivityRules
{
	/** The property carrying the rules, both in the body of a step and in the built node. */
	public const PROPERTY = 'Rules';

	/** The construction that runs an activity: its expression carries the activity itself. */
	private const ACTION_CONSTRUCTION = 'action';

	/** The construction that states an output port of the wrapper: its expression carries the port and its title. */
	private const OUTPUT_CONSTRUCTION = 'output';

	/**
	 * @return \Generator<array<string, mixed>> expression of every action construction, in the order the rules
	 *                                         write them down
	 */
	public static function actionExpressions(mixed $rules): \Generator
	{
		yield from self::expressions($rules, self::ACTION_CONSTRUCTION);
	}

	/**
	 * @return \Generator<array<string, mixed>> expression of every output construction, in the order the rules
	 *                                         write them down
	 */
	public static function outputExpressions(mixed $rules): \Generator
	{
		yield from self::expressions($rules, self::OUTPUT_CONSTRUCTION);
	}

	/**
	 * Every node name the rules state, however deep: the activity of each action construction, the activities
	 * written inside it, and the same again for the rules of an activity that is a wrapper of its own.
	 *
	 * These are names of the document like an '_id' of a step: the build copies such an activity into the
	 * 'Children' of the wrapper and it becomes an activity of the template. A wrapper writing its rules out
	 * states no '_inner_id' - the rules name the activity instead - so this is the only place the name stands in.
	 *
	 * @return list<string>
	 */
	public static function statedActivityNames(mixed $rules): array
	{
		$names = [];

		foreach (self::actionExpressions($rules) as $expression)
		{
			$activityData = $expression['activityData'] ?? null;
			if (!is_array($activityData))
			{
				continue;
			}

			foreach (self::activitiesWrittenBy($activityData) as $activity)
			{
				$name = self::nameOf($activity);
				if ($name !== null)
				{
					$names[] = $name;
				}

				array_push($names, ...self::statedActivityNames(self::rulesOf($activity)));
			}
		}

		return $names;
	}

	/**
	 * The names an activity written out verbatim brings into the template: its own and the ones of the
	 * activities written inside it. The build copies the activity into the wrapper as it stands, so each of
	 * them is an activity of the built template and claims its name there.
	 *
	 * Fewer names than {@see self::statedActivityNames()} on purpose: a name the rules of that activity state
	 * becomes an activity only where those rules are built into 'Children', and a copy is written and not built.
	 *
	 * @param array<string, mixed> $activityData
	 *
	 * @return list<string>
	 */
	public static function activityNamesWrittenBy(array $activityData): array
	{
		$names = [];

		foreach (self::activitiesWrittenBy($activityData) as $activity)
		{
			$name = self::nameOf($activity);
			if ($name !== null)
			{
				$names[] = $name;
			}
		}

		return $names;
	}

	/**
	 * @return \Generator<array<string, mixed>> the activity itself and every activity written inside it
	 */
	private static function activitiesWrittenBy(array $activityData): \Generator
	{
		yield $activityData;

		foreach ($activityData['Children'] ?? [] as $inner)
		{
			if (is_array($inner))
			{
				yield from self::activitiesWrittenBy($inner);
			}
		}
	}

	/**
	 * @return \Generator<array<string, mixed>> expression of every construction of the type asked for
	 */
	private static function expressions(mixed $rules, string $constructionType): \Generator
	{
		if (!is_array($rules))
		{
			return;
		}

		foreach ($rules as $portRule)
		{
			$cards = is_array($portRule) ? ($portRule['ruleCards'] ?? []) : [];
			foreach ($cards as $card)
			{
				$constructions = is_array($card) ? ($card['constructions'] ?? []) : [];
				foreach ($constructions as $construction)
				{
					if (!is_array($construction) || ($construction['type'] ?? null) !== $constructionType)
					{
						continue;
					}

					$expression = $construction['expression'] ?? null;
					if (is_array($expression))
					{
						yield $expression;
					}
				}
			}
		}
	}

	/** @param array<string, mixed> $activityData */
	private static function nameOf(array $activityData): ?string
	{
		$name = $activityData['Name'] ?? null;

		return is_string($name) && $name !== '' ? $name : null;
	}

	/** @param array<string, mixed> $activityData */
	private static function rulesOf(array $activityData): mixed
	{
		$properties = $activityData['Properties'] ?? null;

		return is_array($properties) ? ($properties[self::PROPERTY] ?? null) : null;
	}
}
