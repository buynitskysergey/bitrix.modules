<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Category;

use Bitrix\Crm\PhaseSemantics;
use Bitrix\Crm\V2\Internal\Entity\Category\StageData;
use Bitrix\Crm\V2\Public\Entity\Category\CategoryError;
use Bitrix\Main\Result;

/**
 * The stage rules the storage boundary does not hold, in one place: a system stage is never
 * deleted, a category never ends up without its success stage, and no change puts a stage on a side
 * of that success stage the set cannot keep it on.
 *
 * Every operation of the domain able to delete, change or reorder a stage goes through here - single
 * deletion, a single change and the group replacement of a stage set alike. The boundary is of no
 * help with any of the rules: {@see \Bitrix\Crm\StatusTable::onBeforeDelete()} looks at nothing but
 * the items sitting on the stage, never reads the `SYSTEM` field, and skips even that check when the
 * stage set or the factory behind it cannot be resolved, while
 * {@see \Bitrix\Crm\StatusTable::onBeforeUpdate()} weighs neither semantics nor position against the
 * rest of the set. Without this class an external client deletes the initial stage, the success stage
 * or the failure stage of a category - or demotes the success stage and leaves the category without
 * one at all.
 *
 * Nothing here reaches storage: the stages arrive already read, so a caller decides how it got them
 * and this class stays a pure predicate over them.
 *
 * @internal
 */
class StageGuard
{
	/**
	 * Whether $stage may be deleted at all.
	 *
	 * The answer follows the system flag alone. Being final is a different property that happens to
	 * overlap: `NEW` is a system stage in process semantics, `APOLOGY` of a Deal is system and final,
	 * and a stage a user added can become the failure one while staying non-system. A non-system final
	 * stage is deleted normally.
	 */
	public function canDelete(StageData $stage): Result
	{
		return $stage->isSystem ? self::refuse() : new Result();
	}

	/**
	 * Whether the set of stages an operation leaves behind is still a valid one: every stage it deletes
	 * may be deleted, and a stage with success semantics survives it.
	 *
	 * The question is asked about the operation as a whole and the answer refuses it as a whole - a
	 * group replacement gets its refusal before a transaction is opened rather than halfway through it.
	 *
	 * A stage still holding items is not asked about here: the boundary refuses that write itself
	 * ({@see \Bitrix\Crm\StatusTable::onBeforeDelete()}), and the mapper turns its refusal into
	 * {@see CategoryError::DEPENDENT_ITEMS_EXIST}. Two implementations of one rule would drift apart.
	 *
	 * @param StageData[] $currentStages the stages the category has now
	 * @param StageData[] $toDelete stages of $currentStages the operation removes
	 * @param StageData[] $toAdd stages the operation creates; only their semantics matters here
	 */
	public function checkSetAfterChanges(array $currentStages, array $toDelete, array $toAdd): Result
	{
		foreach ($toDelete as $stage)
		{
			$refusal = $this->canDelete($stage);
			if (!$refusal->isSuccess())
			{
				return $refusal;
			}
		}

		if (!$this->hasSuccessStage($this->remainingAfter($currentStages, $toDelete, $toAdd)))
		{
			return self::refuse();
		}

		return new Result();
	}

	/**
	 * Whether the category still holds together once the stage $changed addresses is changed the way
	 * it spells it: its success stage survives the change, and no stage of the set ends up on a side
	 * of that success stage the set cannot keep it on.
	 *
	 * The boundary holds all of this when a stage is added and almost none of it when one is changed
	 * ({@see \Bitrix\Crm\StatusTable::onBeforeAdd()} against
	 * {@see \Bitrix\Crm\StatusTable::onBeforeUpdate()}, which refuses a stage raised to success
	 * semantics and nothing else). Without the question asked here a client demotes the success stage
	 * of a category and cannot put it back afterwards - raising a stage to success semantics is the
	 * one thing the boundary does refuse, whether or not the category has a success stage left.
	 *
	 * A layout the category already stands in is never refused here. An old funnel can hold a process
	 * stage behind its success one, and a change leaving that stage where it is - a rename - is not
	 * the operation that put it there. What is refused is a stage this very change misplaces.
	 *
	 * @param StageData[] $currentStages the stages the category has now, ordered by `sort`
	 * @param StageData $changed the stage of $currentStages as the change leaves it, addressed by
	 *        `stageId`; a stage the category does not hold changes nothing
	 */
	public function checkStageAfterChange(array $currentStages, StageData $changed): Result
	{
		$afterChange = self::withStageChanged($currentStages, $changed);

		if ($this->hasSuccessStage($currentStages) && !$this->hasSuccessStage($afterChange))
		{
			return self::refuse();
		}

		$misplacedBefore = self::misplacedStageIds($currentStages);
		foreach (array_keys(self::misplacedStageIds($afterChange)) as $stageId)
		{
			if (!isset($misplacedBefore[$stageId]))
			{
				return self::refuse();
			}
		}

		return new Result();
	}

	/**
	 * @param StageData[] $currentStages
	 * @return StageData[]
	 */
	private static function withStageChanged(array $currentStages, StageData $changed): array
	{
		$afterChange = [];
		foreach ($currentStages as $stage)
		{
			$afterChange[] = $stage->stageId === $changed->stageId ? $changed : $stage;
		}

		return $afterChange;
	}

	/**
	 * The stages of $stages standing where the set cannot keep them: a second stage in success
	 * semantics, a failure stage ahead of the success one and a process stage behind it.
	 *
	 * Positions are weighed the way the boundary weighs them when a stage is added
	 * ({@see \Bitrix\Crm\StatusTable::onBeforeAdd()}), an equal `sort` included: two stages sharing a
	 * position are ordered by a record id this domain does not carry, so a position equal to the one
	 * of the success stage is refused on either side of it there and here.
	 *
	 * @param StageData[] $stages
	 * @return array<string, true> keyed by the identifier of the stage
	 */
	private static function misplacedStageIds(array $stages): array
	{
		$misplaced = [];

		$successSort = null;
		foreach ($stages as $stage)
		{
			if (!PhaseSemantics::isSuccess($stage->semantics))
			{
				continue;
			}

			if ($successSort === null)
			{
				$successSort = $stage->sort;
			}
			else
			{
				$misplaced[$stage->stageId] = true;
			}
		}

		if ($successSort === null)
		{
			return $misplaced;
		}

		foreach ($stages as $stage)
		{
			if (PhaseSemantics::isSuccess($stage->semantics))
			{
				continue;
			}

			$standsWrong = PhaseSemantics::isFinal($stage->semantics)
				? $stage->sort <= $successSort
				: $stage->sort >= $successSort
			;
			if ($standsWrong)
			{
				$misplaced[$stage->stageId] = true;
			}
		}

		return $misplaced;
	}

	/**
	 * @param StageData[] $currentStages
	 * @param StageData[] $toDelete
	 * @param StageData[] $toAdd
	 * @return StageData[]
	 */
	private function remainingAfter(array $currentStages, array $toDelete, array $toAdd): array
	{
		$deletedIds = [];
		foreach ($toDelete as $stage)
		{
			$deletedIds[$stage->stageId] = true;
		}

		$remaining = [];
		foreach ($currentStages as $stage)
		{
			if (!isset($deletedIds[$stage->stageId]))
			{
				$remaining[] = $stage;
			}
		}

		return array_merge($remaining, $toAdd);
	}

	/**
	 * @param StageData[] $stages
	 */
	private function hasSuccessStage(array $stages): bool
	{
		foreach ($stages as $stage)
		{
			if (PhaseSemantics::isSuccess($stage->semantics))
			{
				return true;
			}
		}

		return false;
	}

	private static function refuse(): Result
	{
		return (new Result())->addError(CategoryError::OPERATION_NOT_ALLOWED_FOR_STAGE->toError());
	}
}
