<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\Category;

use Bitrix\Crm\V2\Internal\Service\Category\Handler\UpdateCategoryCommandHandler;
use Bitrix\Crm\V2\Public\Entity\Category\Category;
use Bitrix\Crm\V2\Public\Entity\Category\CategoryError;
use Bitrix\Main\Result;

/**
 * Changes a category (pipeline) of an entity type.
 *
 * ```php
 * $category = CategoryProvider::forEntityType(EntityType::deal(), $userId)->getById($id);
 * $result = (new UpdateCategoryCommand($category->setName('Wholesale'), $userId))->run();
 * ```
 *
 * The identifier and the entity type of the category say which category is meant, and the fields set
 * on it are the write: a field left alone keeps its stored value, and a field the entity type does not
 * accept is refused with {@see CategoryError::FIELD_NOT_WRITABLE} naming it, and a rename to nothing
 * but blanks with {@see CategoryError::FIELD_VALUE_NOT_ALLOWED} naming it the same way. Making a
 * category the default one takes the flag from the category that holds it, and either both writes
 * happen or neither does. On success the category is filled with what storage holds afterwards.
 *
 * A category the user may not read answers {@see CategoryError::CATEGORY_NOT_FOUND} - the same
 * absence a category that is not there answers, so that permissions reveal nothing. A category the
 * user may read but not configure answers {@see \Bitrix\Crm\Controller\ErrorCode::ACCESS_DENIED}:
 * by then there is nothing left to hide.
 */
final class UpdateCategoryCommand extends AbstractCategoryCommand
{
	public function __construct(
		private readonly Category $category,
		int $userId,
	)
	{
		parent::__construct($userId);
	}

	public function getCategory(): Category
	{
		return $this->category;
	}

	protected function execute(): Result
	{
		$result = (new UpdateCategoryCommandHandler())->handle(
			self::entityTypeOf($this->category),
			$this->getUserId(),
			self::idOf($this->category),
			self::fieldsToWrite($this->category),
		);

		return self::applyWriteResult($this->category, $result);
	}
}
