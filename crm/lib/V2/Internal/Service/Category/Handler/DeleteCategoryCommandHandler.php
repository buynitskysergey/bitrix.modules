<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Category\Handler;

use Bitrix\Crm\V2\Internal\Entity\Category\CategoryData;
use Bitrix\Crm\V2\Public\Entity\Category\CategoryError;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Main\Result;

/**
 * Deletes a category (pipeline) of an entity type together with everything the boundary cascades from
 * it - stages, role permissions, user field values.
 *
 * Whether the category may be deleted at all is the boundary's answer, not a second opinion of ours:
 * dependent items, the system flag and the default category of an entity type that still has items are
 * all checked there ({@see \Bitrix\Crm\Model\ItemCategoryTable::onBeforeDelete()},
 * {@see \Bitrix\Crm\Category\DealCategory::hasDependencies()}), and the mapper turns each refusal into
 * its domain error. Repeating those rules here would give the domain two implementations of one rule.
 *
 * The single case answered before the boundary is a virtual category: it has no row of its own, so a
 * transaction would guarantee nothing for it, and the boundary reports it by throwing.
 *
 * @internal
 */
class DeleteCategoryCommandHandler extends AbstractCategoryCommandHandler
{
	/**
	 * A successful result carries no data: the category is gone, and what the boundary cascaded with it
	 * is described by the contract rather than reported back.
	 */
	public function handle(EntityType $entityType, int $userId, int $categoryId): Result
	{
		$repository = $this->createRepository($entityType->getId());

		$resolved = $this->resolveCategoryToWrite($repository, $entityType, $userId, $categoryId);
		if (!$resolved->isSuccess())
		{
			return $resolved;
		}

		if ($this->isVirtual(self::categoryOf($resolved)))
		{
			return self::refuse(CategoryError::OPERATION_NOT_ALLOWED_FOR_CATEGORY);
		}

		$result = $this->writeInTransaction($repository, static fn (): Result => $repository->delete($categoryId));

		return $result->isSuccess() ? new Result() : $result;
	}

	/**
	 * A category that no table holds. Deal keeps its own in module options
	 * ({@see \Bitrix\Crm\Category\Entity\DealDefaultCategory}) and Contact and Company have nowhere to
	 * keep theirs at all ({@see \Bitrix\Crm\Category\Entity\ClientDefaultCategory}); all three refuse
	 * deletion with an exception, which is an expected client refusal and not a failure, so it is
	 * answered before a transaction that could not roll such a write back anyway is opened.
	 */
	private function isVirtual(CategoryData $category): bool
	{
		return $category->id === 0;
	}
}
