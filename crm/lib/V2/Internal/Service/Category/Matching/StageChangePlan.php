<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Category\Matching;

use Bitrix\Crm\V2\Internal\Entity\Category\StageData;
use Bitrix\Crm\V2\Public\Entity\Category\CategoryError;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * What a {@see StageMatchingStrategy} makes of the input: which stages of the category are updated,
 * which are created and which are deleted - or the refusal that stops the replacement before
 * anything is written.
 *
 * The refusal is part of this type rather than an exception because a strategy turns down routine
 * client mistakes: an identifier the category does not have, a final stage the client tried to
 * rename. Both are answers of the domain, and the plan reports them the way the rest of the domain
 * reports a refusal - as a failed {@see Result}.
 *
 * The three lists are disjoint and every one of them is addressed by `stageId`: a stage of
 * `toUpdate` and of `toDelete` is one the category has now, a stage of `toAdd` has no identifier
 * yet. What a plan does not spell out is the order the category ends up in - that is read off the
 * `sort` of the stages it carries, see
 * {@see \Bitrix\Crm\V2\Internal\Service\Category\StageSetReplacer::replace()}.
 *
 * @internal
 */
final class StageChangePlan extends Result
{
	/**
	 * @param StageData[] $toUpdate existing stages with the values they must end up with
	 * @param StageData[] $toAdd stages to create; `stageId` is empty, `sort` is where they go
	 * @param StageData[] $toDelete existing stages to remove, as the category has them now
	 */
	private function __construct(
		public readonly array $toUpdate = [],
		public readonly array $toAdd = [],
		public readonly array $toDelete = [],
	)
	{
		parent::__construct();
	}

	/**
	 * @param StageData[] $toUpdate
	 * @param StageData[] $toAdd
	 * @param StageData[] $toDelete
	 */
	public static function of(array $toUpdate, array $toAdd, array $toDelete): self
	{
		return new self($toUpdate, $toAdd, $toDelete);
	}

	public static function refuse(CategoryError $error): self
	{
		return self::fail($error->toError());
	}

	public static function fail(Error $error): self
	{
		$plan = new self();
		$plan->addError($error);

		return $plan;
	}
}
