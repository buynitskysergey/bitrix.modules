<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Category\Handler;

use Bitrix\Crm\V2\Internal\Repository\Category\StageRepositoryInterface;
use Bitrix\Crm\V2\Public\Entity\Category\CategoryError;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Main\Result;

/**
 * Creates a stage in a category (pipeline) of an entity type.
 *
 * The semantics of the new stage is the caller's to choose, and the rules around it belong to the
 * boundary: a second success stage, a failure stage placed before the success one and a process
 * stage placed behind it are all refused by the ORM events of
 * {@see \Bitrix\Crm\StatusTable::onBeforeAdd()}, and the mapper turns each refusal into
 * {@see CategoryError::OPERATION_NOT_ALLOWED_FOR_STAGE}. Repeating those rules here would give the
 * domain two implementations of one rule.
 *
 * @internal
 */
class AddStageCommandHandler extends AbstractStageCommandHandler
{
	/**
	 * Field names are the domain ones - the property names of
	 * {@see \Bitrix\Crm\V2\Internal\Entity\Category\StageData} - and the ones a write accepts are
	 * {@see StageRepositoryInterface::WRITABLE_FIELDS}; any other is refused with
	 * {@see CategoryError::FIELD_NOT_WRITABLE} naming that field in the custom data of the error. A
	 * `sort` the caller did not pass puts the stage after the last process stage of the category.
	 *
	 * @param array<string, mixed> $fields
	 * @return Result The created stage in {@see StageRepositoryInterface::DATA_KEY_STAGE}.
	 */
	public function handle(EntityType $entityType, int $userId, int $categoryId, array $fields): Result
	{
		$refusal = $this->refuseStageWriteIn($entityType, $userId, $categoryId);
		if ($refusal !== null)
		{
			return $refusal;
		}

		$repository = $this->createRepository($entityType->getId());

		return $this->writeInTransaction(
			$repository,
			$categoryId,
			static fn (): Result => $repository->add($categoryId, $fields),
		);
	}
}
