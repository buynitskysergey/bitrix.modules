<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Category\Handler;

use Bitrix\Crm\V2\Internal\Entity\Category\StageData;
use Bitrix\Crm\V2\Internal\Exception\Category\StorageBoundaryExceptionMapper;
use Bitrix\Crm\V2\Internal\Repository\Category\StageRepositoryInterface;
use Bitrix\Crm\V2\Internal\Service\Category\CategoryAccess;
use Bitrix\Crm\V2\Internal\Service\Category\StageGuard;
use Bitrix\Crm\V2\Public\Entity\Category\CategoryError;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Main\Result;

/**
 * Changes a stage of a category (pipeline) of an entity type.
 *
 * A stage of a category the user may not read answers {@see CategoryError::CATEGORY_NOT_FOUND}
 * rather than a refusal of access, so that a category hidden by permissions does not give itself
 * away through its stages.
 *
 * @internal
 */
class UpdateStageCommandHandler extends AbstractStageCommandHandler
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
	 * Fields follow {@see AddStageCommandHandler::handle()}. A field the write does not mention keeps
	 * its value, the position of the stage included.
	 *
	 * The stage is resolved before the write instead of letting the boundary refuse a stage that is
	 * not there: the repository reports that by throwing
	 * {@see \Bitrix\Main\ObjectNotFoundException}, which carries no domain meaning and would reach the
	 * caller as a failure of the domain rather than as the refusal it is.
	 *
	 * What the change leaves the whole set looking like goes through {@see StageGuard} for the same
	 * reason a deletion does: the boundary weighs a semantics and a position against the rest of the
	 * set when a stage is added and no longer weighs either when one is changed
	 * ({@see \Bitrix\Crm\StatusTable::onBeforeUpdate()}), so this is the only place the rules hold on
	 * this path.
	 *
	 * @param array<string, mixed> $fields
	 * @return Result The changed stage in {@see StageRepositoryInterface::DATA_KEY_STAGE}.
	 */
	public function handle(
		EntityType $entityType,
		int $userId,
		int $categoryId,
		string $stageId,
		array $fields,
	): Result
	{
		$refusal = $this->refuseStageWriteIn($entityType, $userId, $categoryId);
		if ($refusal !== null)
		{
			return $refusal;
		}

		$repository = $this->createRepository($entityType->getId());
		$stage = $repository->getById($categoryId, $stageId);
		if ($stage === null)
		{
			return self::refuse(CategoryError::STAGE_NOT_FOUND);
		}

		$allowed = $this->guard->checkStageAfterChange(
			$repository->getAllByCategory($categoryId),
			self::stageAsChanged($stage, $fields),
		);
		if (!$allowed->isSuccess())
		{
			return $allowed;
		}

		// The transaction is here because clearing the colour of a stage is a second write past the
		// boundary, and a refusal of it arrives with the name, the semantics and the position of the
		// stage already stored.
		return $this->writeInTransaction(
			$repository,
			$categoryId,
			static fn (): Result => $repository->update($categoryId, $stageId, $fields),
		);
	}

	/**
	 * The stage as the write leaves it, in the two fields that decide where a stage may stand. The
	 * rest of it is carried over from storage: a name and a colour are the same wherever the stage
	 * ends up, and a field outside the write contract is refused by the repository rather than read
	 * here.
	 *
	 * @param array<string, mixed> $fields
	 */
	private static function stageAsChanged(StageData $stage, array $fields): StageData
	{
		return new StageData(
			$stage->stageId,
			$stage->categoryId,
			$stage->name,
			$stage->color,
			isset($fields['semantics']) ? (string)$fields['semantics'] : $stage->semantics,
			isset($fields['sort']) ? (int)$fields['sort'] : $stage->sort,
			$stage->isSystem,
		);
	}
}
