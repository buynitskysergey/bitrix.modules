<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Provider\Category;

use Bitrix\Crm\V2\Internal\Entity\Category\StageData;
use Bitrix\Crm\V2\Internal\Repository\Category\StageRepository;
use Bitrix\Crm\V2\Internal\Repository\Category\StageRepositoryInterface;
use Bitrix\Crm\V2\Internal\Service\Category\CategoryAccess;
use Bitrix\Crm\V2\Internal\Service\Category\StageReader;
use Bitrix\Crm\V2\Public\Entity\Category\Stage;
use Bitrix\Crm\V2\Public\Entity\Category\StageCollection;
use Bitrix\Crm\V2\Public\Entity\Category\StageSemantics;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Main\Provider\Params\PagerInterface;

/**
 * Reads the stages of one category (pipeline) of one entity type on behalf of one user.
 *
 * ```php
 * $stages = StageProvider::forEntityType(EntityType::deal(), $userId)->getList($categoryId);
 * ```
 *
 * The category is part of every read: a stage only means anything inside the category it stands in,
 * and its identifier is namespaced by that category - `C2:NEW` for a Deal category, `DT128_5:NEW`
 * for a smart process one.
 *
 * The user is part of the provider rather than of a call, and there is no way to read without it:
 * the right to read a category is the right to read its stages. A category the user may not read
 * gives up no stage, exactly as a category that does not exist and as an entity type that keeps its
 * stages outside categories altogether - Lead and Quote do, so this provider has nothing to give
 * for them. Every field of a stage is read: the set is small enough that a partial one would only
 * complicate the contract.
 *
 * PAGING. The order is the one the domain keeps - `sort`, then the stage record - so the same page
 * holds the same stages as long as the set itself does not change.
 *
 * CACHING. The provider keeps nothing of its own: what is cached is cached at the storage boundary,
 * for a day and shared with the rest of the portal. A write made in the same request is therefore
 * visible here only after that boundary has been told about it.
 */
final class StageProvider
{
	private readonly StageReader $reader;
	private readonly CategoryAccess $access;
	private readonly StageRepositoryInterface $repository;

	private function __construct(
		private readonly EntityType $entityType,
		private readonly int $userId,
	)
	{
		$this->reader = new StageReader();
		$this->access = new CategoryAccess();
		$this->repository = new StageRepository($entityType->getId());
	}

	/**
	 * A provider of the stages of $entityType, reading as $userId. An anonymous user (`0` and below)
	 * may read nothing, so it gets an empty set and no stage.
	 */
	public static function forEntityType(EntityType $entityType, int $userId): self
	{
		return new self($entityType, $userId);
	}

	/**
	 * The stage $stageId of the category $categoryId, or `null` when the user may not read that
	 * category and when the category holds no such stage - the two are indistinguishable from the
	 * outside. A stage of another category is not found either: the identifier is looked up inside
	 * the category it is asked for.
	 */
	public function getById(int $categoryId, string $stageId): ?Stage
	{
		if (!$this->access->canRead($this->entityType->getId(), $this->userId, $categoryId))
		{
			return null;
		}

		$stage = $this->repository->getById($categoryId, $stageId);

		return $stage === null ? null : self::toStage($stage);
	}

	/**
	 * The stage $stageId of this entity type, in whichever category holds it, or `null` when none of
	 * the categories the user may read does - and when there is no such stage at all, the two being
	 * indistinguishable from the outside exactly as they are in {@see self::getById()}.
	 *
	 * The category is not asked for here, and this is the one read of the provider where it is not: a
	 * stage is addressed from the outside by its identifier alone, and which category that identifier
	 * belongs to is knowledge of the domain rather than of the caller. Reading it out of the
	 * identifier - `C2:NEW` names Deal category `2` - is what a caller must never do itself.
	 */
	public function findById(string $stageId): ?Stage
	{
		$readableCategoryIds = $this->access->getReadableCategoryIds($this->entityType->getId(), $this->userId);
		$stage = $this->repository->findById($stageId, $readableCategoryIds);

		return $stage === null ? null : self::toStage($stage);
	}

	/**
	 * One page of the stages of $categoryId. Without a $pager the whole set of the category comes
	 * back.
	 */
	public function getList(int $categoryId, ?PagerInterface $pager = null): StageCollection
	{
		$collection = new StageCollection();
		foreach ($this->readVisible($categoryId, $pager) as $stage)
		{
			$collection->add(self::toStage($stage));
		}

		return $collection;
	}

	/**
	 * How many stages the category $categoryId holds for this user - the size of the set the pages
	 * come from, so no pager changes it. The set is read to be counted: a category holds a handful of
	 * stages, the storage boundary hands them out of its own cache, and counting the very set the
	 * list comes from is what keeps the two from ever disagreeing.
	 */
	public function getCount(int $categoryId): int
	{
		return count($this->readVisible($categoryId));
	}

	/**
	 * @return StageData[]
	 */
	private function readVisible(int $categoryId, ?PagerInterface $pager = null): array
	{
		return $this->reader->getList($this->entityType->getId(), $categoryId, $this->userId, $pager);
	}

	/**
	 * A stage that comes from the domain carries no changes of its own: the fields are filled and the
	 * change marks the setters left are dropped, so a stage handed to a write later carries only what
	 * its caller has set.
	 *
	 * A stage without a stored colour gets an empty string rather than `null`: `null` is what a write
	 * reads as "leave the colour alone", and a stage that was read has no such field.
	 */
	private static function toStage(StageData $data): Stage
	{
		$stage = (new Stage())
			->setStageId($data->stageId)
			->setCategoryId($data->categoryId)
			->setName($data->name)
			->setColor($data->color ?? '')
			->setSemantics(StageSemantics::internalFromStorageValue($data->semantics))
			->setSort($data->sort)
			->setIsSystem($data->isSystem)
		;
		$stage->resetChangedFields();

		return $stage;
	}
}
