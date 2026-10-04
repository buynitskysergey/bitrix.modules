<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Category\Handler;

use Bitrix\Crm\V2\Internal\Exception\Category\StorageBoundaryExceptionMapper;
use Bitrix\Crm\V2\Internal\Service\Category\CategoryAccess;
use Bitrix\Crm\V2\Internal\Service\Category\StageGuard;
use Bitrix\Crm\V2\Public\Entity\Category\CategoryError;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Main\Result;

/**
 * Deletes a stage of a category (pipeline) of an entity type.
 *
 * Whether the stage still holds items is the boundary's answer, not a second opinion of ours
 * ({@see \Bitrix\Crm\StatusTable::onBeforeDelete()}), and the mapper turns its refusal into
 * {@see CategoryError::DEPENDENT_ITEMS_EXIST}. What the boundary does not answer is whether the
 * stage may be deleted at all: it never reads the system flag, so without {@see StageGuard} an
 * external client would delete the initial stage, the success stage or the failure stage of a
 * category.
 *
 * @internal
 */
class DeleteStageCommandHandler extends AbstractStageCommandHandler
{
	public function __construct(
		CategoryAccess $access = new CategoryAccess(),
		StorageBoundaryExceptionMapper $exceptionMapper = new StorageBoundaryExceptionMapper(),
		private readonly StageGuard $guard = new StageGuard(),
	)
	{
		parent::__construct($access, $exceptionMapper);
	}

	/**
	 * A successful result carries no data: the stage is gone.
	 *
	 * The whole stage set of the category goes through the guard rather than the stage alone. The
	 * system flag would be answered by {@see StageGuard::canDelete()} on its own, but it is not the
	 * only rule: a success stage that is not a system one is reachable - legacy `crm.status.delete`
	 * deletes a system stage on `FORCED` and `crm.status.add` puts a plain one in its place - and
	 * deleting that one would leave the category without any success stage at all. Both rules hold on
	 * every path of the domain, and this single call covers both.
	 */
	public function handle(EntityType $entityType, int $userId, int $categoryId, string $stageId): Result
	{
		$refusal = $this->refuseStageWriteIn($entityType, $userId, $categoryId);
		if ($refusal !== null)
		{
			return $refusal;
		}

		$repository = $this->createRepository($entityType->getId());

		// Read before the guard and before the write alike: the guard needs the stage itself, and the
		// repository reports a stage that is not there by throwing.
		$stage = $repository->getById($categoryId, $stageId);
		if ($stage === null)
		{
			return self::refuse(CategoryError::STAGE_NOT_FOUND);
		}

		$allowed = $this->guard->checkSetAfterChanges($repository->getAllByCategory($categoryId), [$stage], []);
		if (!$allowed->isSuccess())
		{
			return $allowed;
		}

		$result = $this->writeInTransaction(
			$repository,
			$categoryId,
			static fn (): Result => $repository->delete($categoryId, $stageId),
		);

		return $result->isSuccess() ? new Result() : $result;
	}
}
