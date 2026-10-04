<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Category\Handler;

use Bitrix\Crm\V2\Internal\Repository\Category\CategoryRepositoryInterface;
use Bitrix\Crm\V2\Public\Entity\Category\CategoryError;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Main\Result;

/**
 * Creates a category (pipeline) of an entity type.
 *
 * The tariff limit gets no check of its own: the only entity type that has one is Deal, where the ORM
 * event of the category table enforces it ({@see \Bitrix\Crm\Category\Entity\DealCategoryTable::onBeforeAdd()}),
 * and its refusal arrives as {@see CategoryError::TARIFF_LIMIT_EXCEEDED} through the boundary mapper.
 * Checking it here would mean a second implementation of a rule the boundary already owns.
 *
 * @internal
 */
class AddCategoryCommandHandler extends AbstractCategoryCommandHandler
{
	/**
	 * Field names are the domain ones - the property names of
	 * {@see \Bitrix\Crm\V2\Internal\Entity\Category\CategoryData} - and which of them $entityType
	 * accepts is its own business: a field it does not is refused with
	 * {@see CategoryError::FIELD_NOT_WRITABLE} naming that field in the custom data of the error.
	 *
	 * @param array<string, mixed> $fields
	 * @return Result The created category in {@see CategoryRepositoryInterface::DATA_KEY_CATEGORY}.
	 */
	public function handle(EntityType $entityType, int $userId, array $fields): Result
	{
		$refusal =
			$this->refuseUnsupportedEntityType($entityType)
			?? $this->refuseMissingWriteRight($entityType, $userId, null)
		;
		if ($refusal !== null)
		{
			return $refusal;
		}

		$repository = $this->createRepository($entityType->getId());

		return $this->writeInTransaction($repository, static fn (): Result => $repository->add($fields));
	}
}
