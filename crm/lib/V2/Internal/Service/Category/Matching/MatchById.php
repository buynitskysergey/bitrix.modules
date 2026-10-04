<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Category\Matching;

use Bitrix\Crm\PhaseSemantics;
use Bitrix\Crm\V2\Internal\Entity\Category\StageData;
use Bitrix\Crm\V2\Public\Entity\Category\CategoryError;

/**
 * A stage is the same one when the caller sends back the identifier the category knows it by.
 *
 * That is what tells this strategy apart from the positional one. A renamed stage keeps its
 * identifier, and with it everything else the portal addresses it by - the automation of the funnel,
 * the permissions of the stage, the items standing on it. The client spells its whole intent in one
 * request: a stage it sends with an identifier stays, a stage it sends without one is created, and a
 * stage of the category it does not send at all is deleted.
 *
 * Two answers are refusals rather than guesses. An identifier the category does not have is
 * {@see CategoryError::STAGE_NOT_FOUND} and never a silently created stage: a typo of the client
 * would otherwise delete the stage it meant and put a copy of it in its place, without its settings
 * and its permissions. And a final or system stage is out of the plan entirely - the group
 * replacement neither renames, deletes nor reorders one, and an attempt to change one is answered
 * with {@see CategoryError::OPERATION_NOT_ALLOWED_FOR_STAGE} instead of being ignored, or the client
 * would go on believing the change applied.
 *
 * Nothing here reaches storage or permissions: input in, plan out.
 *
 * @internal
 */
final class MatchById implements StageMatchingStrategy
{
	/**
	 * @param StageData[] $current
	 * @param StageInput[] $input the stages the client sent, in the order they must stand in
	 */
	public function match(array $current, array $input): StageChangePlan
	{
		$storedById = self::indexByStageId($current);

		// Every occurrence of a stage is read, not just the one the plan keeps: an attempt to change an
		// untouchable stage is answered wherever the input carries it, and never dropped along with the
		// duplicate it came in.
		foreach ($input as $item)
		{
			$stage = $item->stageId === null ? null : ($storedById[$item->stageId] ?? null);
			if ($stage !== null && self::isUntouchable($stage) && self::changesAnything($stage, $item))
			{
				return StageChangePlan::refuse(CategoryError::OPERATION_NOT_ALLOWED_FOR_STAGE);
			}
		}

		foreach ($input as $item)
		{
			if ($item->stageId !== null && !isset($storedById[$item->stageId]))
			{
				return StageChangePlan::refuse(CategoryError::STAGE_NOT_FOUND);
			}
		}

		return self::planOf($current, $input, $storedById);
	}

	/**
	 * @param StageData[] $current
	 * @param StageInput[] $input
	 * @param array<string, StageData> $storedById
	 */
	private static function planOf(array $current, array $input, array $storedById): StageChangePlan
	{
		$floor = self::floorOf($current);
		$offset = 0;
		$categoryId = self::categoryIdOf($current);

		$toUpdate = [];
		$toAdd = [];
		$planned = [];
		foreach ($input as $item)
		{
			if ($item->stageId === null)
			{
				$toAdd[] = new StageData(
					'',
					$categoryId,
					$item->name,
					$item->color,
					PhaseSemantics::PROCESS,
					self::sortAt($floor, $offset++),
					false,
				);

				continue;
			}

			$stage = $storedById[$item->stageId];
			// An untouchable stage the client sent back unchanged is not a mistake, it is simply not
			// something the plan has anything to say about. Neither is the same stage sent twice: a
			// plan addresses a stage by its identifier, and one stage cannot stand in two places.
			if (self::isUntouchable($stage) || isset($planned[$stage->stageId]))
			{
				continue;
			}

			$planned[$stage->stageId] = true;
			$toUpdate[] = new StageData(
				$stage->stageId,
				$stage->categoryId,
				$item->name,
				$item->color,
				$stage->semantics,
				self::sortAt($floor, $offset++),
				$stage->isSystem,
			);
		}

		$toDelete = [];
		foreach ($current as $stage)
		{
			if (!self::isUntouchable($stage) && !isset($planned[$stage->stageId]))
			{
				$toDelete[] = $stage;
			}
		}

		return StageChangePlan::of($toUpdate, $toAdd, $toDelete);
	}

	/**
	 * The position the stages of the plan start behind: the last stage that keeps its stored place
	 * ahead of them - the system stage a category starts with, which is the stage its items are
	 * created in.
	 *
	 * The final stages of the category are not part of it, nor is anything standing behind them: a
	 * final stage the plan leaves alone keeps the end of the set whatever positions the plan takes
	 * ({@see \Bitrix\Crm\V2\Internal\Service\Category\StageSetReplacer::replace()}), and the plan
	 * therefore never has to squeeze its stages in front of one.
	 *
	 * @param StageData[] $current
	 */
	private static function floorOf(array $current): int
	{
		$firstFinalSort = null;
		foreach ($current as $stage)
		{
			if (PhaseSemantics::isFinal($stage->semantics))
			{
				$firstFinalSort = $firstFinalSort === null
					? $stage->sort
					: min($firstFinalSort, $stage->sort);
			}
		}

		$floor = 0;
		foreach ($current as $stage)
		{
			if (self::isUntouchable($stage) && ($firstFinalSort === null || $stage->sort < $firstFinalSort))
			{
				$floor = max($floor, $stage->sort);
			}
		}

		return $floor;
	}

	/**
	 * The position of the stage standing $offset stages into the plan.
	 *
	 * The numbers themselves leave the domain nowhere: the core lays the whole set out by them and the
	 * repository re-spaces it by the step of the module afterwards, so all that matters is their order
	 * against the stages that keep their stored position. One position per stage of the plan and never
	 * two stages on one, however long the input is: an order the client asked for that two stages
	 * share is no order at all.
	 */
	private static function sortAt(int $floor, int $offset): int
	{
		return $floor + 1 + $offset;
	}

	/**
	 * Whether $sent asks for anything the stage does not already say. A colour it carries none of is
	 * not a change: the absence of a colour leaves the stored one alone.
	 */
	private static function changesAnything(StageData $stage, StageInput $sent): bool
	{
		return $sent->name !== $stage->name || ($sent->color !== null && $sent->color !== $stage->color);
	}

	/**
	 * A final stage and a system stage alike: the group replacement leaves both exactly as they are.
	 */
	private static function isUntouchable(StageData $stage): bool
	{
		return $stage->isSystem || PhaseSemantics::isFinal($stage->semantics);
	}

	/**
	 * The category the plan is about, as the stages of it spell it - the core knows it anyway, and a
	 * stage created by a plan for a category without a single stage of its own is not a case the
	 * domain has.
	 *
	 * @param StageData[] $current
	 */
	private static function categoryIdOf(array $current): int
	{
		$firstKey = array_key_first($current);

		return $firstKey === null ? 0 : $current[$firstKey]->categoryId;
	}

	/**
	 * @param StageData[] $stages
	 * @return array<string, StageData>
	 */
	private static function indexByStageId(array $stages): array
	{
		$byStageId = [];
		foreach ($stages as $stage)
		{
			$byStageId[$stage->stageId] = $stage;
		}

		return $byStageId;
	}
}
