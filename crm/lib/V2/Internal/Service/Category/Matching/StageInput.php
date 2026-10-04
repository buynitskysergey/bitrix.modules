<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Category\Matching;

/**
 * One stage of a group replacement as the caller sends it: the name the stage must end up with, the
 * colour it may carry, and the identifier of the stage it is - or no identifier at all, which is how
 * a stage that does not exist yet is asked for.
 *
 * Neither semantics nor a position is part of it. The replacement decides what the process stages of
 * a category are and in which order they stand, and the order is the order of the items themselves;
 * the semantics of the set is not something it manages ({@see MatchById}).
 *
 * A `null` colour is the absence of one rather than a value: it leaves the stored colour of a stage
 * alone.
 *
 * @internal
 */
final readonly class StageInput
{
	public function __construct(
		public ?string $stageId,
		public string $name,
		public ?string $color = null,
	)
	{
	}
}
