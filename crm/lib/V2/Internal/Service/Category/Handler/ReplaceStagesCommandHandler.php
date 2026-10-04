<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Category\Handler;

use Bitrix\Crm\V2\Internal\Service\Category\Matching\MatchById;
use Bitrix\Crm\V2\Internal\Service\Category\Matching\StageInput;
use Bitrix\Crm\V2\Internal\Service\Category\StageSetReplacer;
use Bitrix\Crm\V2\Public\Entity\Category\CategoryError;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Main\Result;

/**
 * Brings the whole stage set of one category to the state the caller asks for, matching the stages
 * the caller sends against the ones the category has by identifier.
 *
 * The replacement itself belongs to {@see StageSetReplacer}, which the tool of the AI assistant
 * reaches too. What this scenario adds is what every other write of a stage already does and the
 * core deliberately does not: it refuses an entity type whose stages do not live
 * in categories and a category the user may not read, before the set is touched at all. Without it
 * a write for Lead or Quote - stages without categories - would reach the single stage set of the
 * entity type past the category the caller named, and a type without stages would be refused as a
 * category rather than as a stage ({@see AbstractStageCommandHandler::refuseStageWriteIn()}).
 *
 * Everything after that is the core: the permission to write stages, the invariant of the set, the
 * transaction around the writes and the notification once they hold.
 *
 * @internal
 */
final class ReplaceStagesCommandHandler extends AbstractStageCommandHandler
{
	/**
	 * @param StageInput[] $input the stages the caller sent, in the order they must stand in
	 * @return Result The resulting stage set in {@see StageSetReplacer::DATA_KEY_STAGES} and the
	 *         change counts in {@see StageSetReplacer::DATA_KEY_CHANGE_COUNTS}. A refusal is a failed
	 *         result carrying a {@see CategoryError} or
	 *         {@see \Bitrix\Crm\Controller\ErrorCode::ACCESS_DENIED}, and storage is left as it was.
	 */
	public function handle(EntityType $entityType, int $userId, int $categoryId, array $input): Result
	{
		$refusal = $this->refuseStageWriteIn($entityType, $userId, $categoryId);
		if ($refusal !== null)
		{
			return $refusal;
		}

		return $this->createReplacer()->replace($entityType, $userId, $categoryId, $input, new MatchById());
	}

	protected function createReplacer(): StageSetReplacer
	{
		return new StageSetReplacer($this->access, $this->exceptionMapper);
	}
}
