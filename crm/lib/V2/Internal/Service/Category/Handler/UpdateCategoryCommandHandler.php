<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Category\Handler;

use Bitrix\Crm\V2\Internal\Repository\Category\CategoryRepositoryInterface;
use Bitrix\Crm\V2\Public\Entity\Category\CategoryError;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Main\Result;

/**
 * Changes a category (pipeline) of an entity type.
 *
 * A category the user may not read answers {@see CategoryError::CATEGORY_NOT_FOUND} rather than a
 * refusal of access, so that the answer for a category hidden by permissions is the same as for a
 * category that does not exist.
 *
 * @internal
 */
class UpdateCategoryCommandHandler extends AbstractCategoryCommandHandler
{
	/**
	 * Fields follow {@see AddCategoryCommandHandler::handle()}. A field the write does not mention
	 * keeps its value; making the category the default one takes the flag from the category that holds
	 * it, and both writes either happen or neither does.
	 *
	 * @param array<string, mixed> $fields
	 * @return Result The changed category in {@see CategoryRepositoryInterface::DATA_KEY_CATEGORY}.
	 */
	public function handle(EntityType $entityType, int $userId, int $categoryId, array $fields): Result
	{
		$repository = $this->createRepository($entityType->getId());

		$resolved = $this->resolveCategoryToWrite($repository, $entityType, $userId, $categoryId);
		if (!$resolved->isSuccess())
		{
			return $resolved;
		}

		return $this->writeInTransaction(
			$repository,
			static fn (): Result => $repository->update($categoryId, $fields),
		);
	}
}
