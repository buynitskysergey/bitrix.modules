<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\Category;

use Bitrix\Crm\V2\Internal\Service\Category\Handler\AddCategoryCommandHandler;
use Bitrix\Crm\V2\Public\Entity\Category\Category;
use Bitrix\Crm\V2\Public\Entity\Category\CategoryError;
use Bitrix\Main\Result;

/**
 * Creates a category (pipeline) of an entity type.
 *
 * ```php
 * $category = (new Category())->setEntityTypeId(EntityType::deal()->getId())->setName('Retail');
 * $result = (new AddCategoryCommand($category, $userId))->setScope(Scope::Rest)->run();
 * // $category->getId() on success
 * ```
 *
 * The category says which entity type it is for, and the fields set on it are the ones the write
 * carries; a field the entity type does not accept - the default flag of a Deal category, the system
 * flag and the code of any category - is refused with {@see CategoryError::FIELD_NOT_WRITABLE} naming
 * it, and a name of nothing but blanks with {@see CategoryError::FIELD_VALUE_NOT_ALLOWED} naming it
 * the same way. On success the same category is filled with what storage holds, its identifier
 * included.
 *
 * An entity type without categories ({@see CategoryError::OPERATION_NOT_ALLOWED_FOR_CATEGORY}), a
 * user who may not configure them ({@see \Bitrix\Crm\Controller\ErrorCode::ACCESS_DENIED}) and a
 * tariff limit already reached ({@see CategoryError::TARIFF_LIMIT_EXCEEDED}) are the refusals of this
 * command; the stages a new category starts with are created by the domain along with it.
 */
final class AddCategoryCommand extends AbstractCategoryCommand
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
		$result = (new AddCategoryCommandHandler())->handle(
			self::entityTypeOf($this->category),
			$this->getUserId(),
			self::fieldsToWrite($this->category),
		);

		return self::applyWriteResult($this->category, $result);
	}
}
