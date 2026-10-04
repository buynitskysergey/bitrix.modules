<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\Category;

use Bitrix\Crm\V2\Internal\Service\Category\Handler\DeleteStageCommandHandler;
use Bitrix\Crm\V2\Public\Entity\Category\CategoryError;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Main\Result;

/**
 * Deletes a stage of a category (pipeline) of an entity type.
 *
 * ```php
 * $result = (new DeleteStageCommand(EntityType::deal(), $categoryId, $stageId, $userId))->run();
 * ```
 *
 * Not every stage may go. The stages a category is created with - the initial one, the success one
 * and the failure one - are its system stages and are refused with
 * {@see CategoryError::OPERATION_NOT_ALLOWED_FOR_STAGE}, and so is the last success stage of a
 * category even where that stage is not a system one: a funnel without a success stage is one its
 * kanban and its automation cannot work with. A stage items still stand on is refused with
 * {@see CategoryError::DEPENDENT_ITEMS_EXIST} - they would be left pointing at nothing.
 *
 * A stage the category does not hold answers {@see CategoryError::STAGE_NOT_FOUND}, a category the
 * user may not read answers {@see CategoryError::CATEGORY_NOT_FOUND}, and one the user may read but
 * not configure answers {@see \Bitrix\Crm\Controller\ErrorCode::ACCESS_DENIED}. A successful result
 * carries nothing: the stage is gone.
 */
final class DeleteStageCommand extends AbstractCategoryCommand
{
	public function __construct(
		private readonly EntityType $entityType,
		private readonly int $categoryId,
		private readonly string $stageId,
		int $userId,
	)
	{
		parent::__construct($userId);
	}

	public function getEntityType(): EntityType
	{
		return $this->entityType;
	}

	public function getCategoryId(): int
	{
		return $this->categoryId;
	}

	public function getStageId(): string
	{
		return $this->stageId;
	}

	protected function execute(): Result
	{
		return (new DeleteStageCommandHandler())->handle(
			$this->entityType,
			$this->getUserId(),
			$this->categoryId,
			$this->stageId,
		);
	}
}
