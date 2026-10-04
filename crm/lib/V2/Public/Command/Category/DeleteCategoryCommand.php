<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\Category;

use Bitrix\Crm\V2\Internal\Service\Category\Handler\DeleteCategoryCommandHandler;
use Bitrix\Crm\V2\Public\Entity\Category\CategoryError;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Main\Result;

/**
 * Deletes a category (pipeline) of an entity type together with what the domain cascades from it -
 * its stages, the role permissions given for it, the values of its user fields.
 *
 * ```php
 * $result = (new DeleteCategoryCommand(EntityType::deal(), $categoryId, $userId))->run();
 * ```
 *
 * The deletion is atomic: a category that cannot be deleted whole is not deleted at all, and a
 * refusal in the middle of the cascade leaves every stage where it was. The one case the database
 * cannot cover is a category that has no row of its own - the virtual category of Deal, Contact and
 * Company - and that category is refused outright
 * ({@see CategoryError::OPERATION_NOT_ALLOWED_FOR_CATEGORY}), as is a system one.
 *
 * A category that still holds items is refused with {@see CategoryError::DEPENDENT_ITEMS_EXIST}, one
 * the user may not read with {@see CategoryError::CATEGORY_NOT_FOUND}, and one the user may read but
 * not configure with {@see \Bitrix\Crm\Controller\ErrorCode::ACCESS_DENIED}. A successful result
 * carries nothing: the category is gone, and what went with it is the contract above rather than a
 * report.
 */
final class DeleteCategoryCommand extends AbstractCategoryCommand
{
	public function __construct(
		private readonly EntityType $entityType,
		private readonly int $categoryId,
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

	protected function execute(): Result
	{
		return (new DeleteCategoryCommandHandler())->handle(
			$this->entityType,
			$this->getUserId(),
			$this->categoryId,
		);
	}
}
