<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\Category;

use Bitrix\Crm\V2\Internal\Service\Category\Handler\UpdateStageCommandHandler;
use Bitrix\Crm\V2\Public\Entity\Category\CategoryError;
use Bitrix\Crm\V2\Public\Entity\Category\Stage;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Result;

/**
 * Changes a stage of a category (pipeline) of an entity type.
 *
 * ```php
 * $stage = StageProvider::forEntityType(EntityType::deal(), $userId)->getById($categoryId, $stageId);
 * $result = (new UpdateStageCommand(EntityType::deal(), $stage->setName('Won back'), $userId))->run();
 * ```
 *
 * The identifier of the stage and the category it stands in say which stage is meant, and the fields
 * set on it are the write: a field left alone keeps its stored value, the position of the stage
 * included, and a colour set to `null` leaves the stored colour where it is. A field the domain does
 * not write - the system flag of a stage - is refused with
 * {@see CategoryError::FIELD_NOT_WRITABLE} naming it, and a rename to nothing but blanks with
 * {@see CategoryError::FIELD_VALUE_NOT_ALLOWED} naming it the same way. On success the stage is
 * filled with what storage holds afterwards.
 *
 * Moving a stage and changing what it means are the same write here, and what the domain weighs is
 * the set the change would leave behind rather than the fields it carries: a change taking the
 * success stage of the category away, putting a failure stage ahead of the success one or a process
 * stage behind it answers {@see CategoryError::OPERATION_NOT_ALLOWED_FOR_STAGE}. Both fields travel
 * in one write, so a stage becomes a process one and moves ahead of the success stage in a single
 * call. A layout the category already stands in is not refused for standing there - only a stage
 * this write misplaces is.
 *
 * Raising a stage to success semantics is refused whatever the category looks like, one left with no
 * success stage at all included: which stage a category succeeds at is settled when the category is
 * created, and no command of this domain moves it - a group replacement writes the name and the
 * colour of a stage and nothing else ({@see ReplaceStagesCommand}).
 *
 * A stage the category does not hold answers {@see CategoryError::STAGE_NOT_FOUND}, a category the
 * user may not read answers {@see CategoryError::CATEGORY_NOT_FOUND} - the same absence a category
 * that is not there answers - and a category the user may read but not configure answers
 * {@see \Bitrix\Crm\Controller\ErrorCode::ACCESS_DENIED}.
 */
final class UpdateStageCommand extends AbstractCategoryCommand
{
	public function __construct(
		private readonly EntityType $entityType,
		private readonly Stage $stage,
		int $userId,
	)
	{
		parent::__construct($userId);
	}

	public function getEntityType(): EntityType
	{
		return $this->entityType;
	}

	public function getStage(): Stage
	{
		return $this->stage;
	}

	/**
	 * @throws ArgumentException The stage names no category or no identifier of its own.
	 */
	protected function execute(): Result
	{
		$result = (new UpdateStageCommandHandler())->handle(
			$this->entityType,
			$this->getUserId(),
			self::stageCategoryIdOf($this->stage),
			self::stageIdOf($this->stage),
			self::stageFieldsToWrite($this->stage),
		);

		return self::applyStageWriteResult($this->stage, $result);
	}
}
