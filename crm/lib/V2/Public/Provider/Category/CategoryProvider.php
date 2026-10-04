<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Provider\Category;

use Bitrix\Crm\V2\Internal\Entity\Category\CategoryData;
use Bitrix\Crm\V2\Internal\Repository\Category\CategoryRepository;
use Bitrix\Crm\V2\Internal\Repository\Category\CategoryRepositoryInterface;
use Bitrix\Crm\V2\Internal\Service\Category\CategoryAccess;
use Bitrix\Crm\V2\Internal\Service\Category\CategoryReader;
use Bitrix\Crm\V2\Public\Entity\Category\Category;
use Bitrix\Crm\V2\Public\Entity\Category\CategoryCollection;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Main\Provider\Params\PagerInterface;

/**
 * Reads the categories (pipelines) of one entity type on behalf of one user.
 *
 * ```php
 * $categories = CategoryProvider::forEntityType(EntityType::deal(), $userId)->getList(new Pager(50, 0));
 * ```
 *
 * The user is part of the provider rather than of a call, and there is no way to read without it: a
 * category is visible to whoever may read the items in it, so a read outside a user has no meaning
 * here. Every field of a category is read - the set is small enough that asking a caller which of
 * them it needs would buy nothing.
 *
 * Whether a category is missing or merely unreadable is not told apart: {@see self::getById()}
 * answers `null` to both. An entity type without categories at all - Lead and Quote - has an empty
 * set and no category to get, which is an answer and not a refusal.
 *
 * PAGING. The page is cut in memory, unlike every other paginated read of the module: the set of
 * categories can only be read whole (it is cached as a whole, and for Deal it carries a virtual
 * category `0` that has no row in any table) and the permission filter has always run in memory.
 * The order is the one the domain keeps - `sort`, then `id` - so the same page holds the same
 * categories as long as the set itself does not change.
 *
 * CACHING. The provider keeps nothing of its own: what is cached is cached at the storage boundary,
 * for a day and shared with the rest of the portal. A write made in the same request is therefore
 * visible here only after that boundary has been told about it.
 */
final class CategoryProvider
{
	private readonly CategoryReader $reader;
	private readonly CategoryAccess $access;
	private readonly CategoryRepositoryInterface $repository;

	private function __construct(
		private readonly EntityType $entityType,
		private readonly int $userId,
	)
	{
		$this->reader = new CategoryReader();
		$this->access = new CategoryAccess();
		$this->repository = new CategoryRepository($entityType->getId());
	}

	/**
	 * A provider of the categories of $entityType, reading as $userId. An anonymous user (`0` and
	 * below) may read nothing, so it gets an empty set and no category.
	 */
	public static function forEntityType(EntityType $entityType, int $userId): self
	{
		return new self($entityType, $userId);
	}

	/**
	 * The category $id of the entity type, or `null` when the user may not read it and when there is
	 * no such category - the two are indistinguishable from the outside.
	 */
	public function getById(int $id): ?Category
	{
		if (!$this->access->canRead($this->entityType->getId(), $this->userId, $id))
		{
			return null;
		}

		$category = $this->repository->getById($id);

		return $category === null ? null : self::toCategory($category);
	}

	/**
	 * One page of the categories the user may read. Without a $pager the whole visible set comes back.
	 */
	public function getList(?PagerInterface $pager = null): CategoryCollection
	{
		$collection = new CategoryCollection();
		foreach ($this->reader->getList($this->entityType->getId(), $this->userId, $pager) as $category)
		{
			$collection->add(self::toCategory($category));
		}

		return $collection;
	}

	/**
	 * How many categories the user may read - the size of the set the pages come from, so no pager
	 * changes it.
	 */
	public function getCount(): int
	{
		return $this->reader->getCount($this->entityType->getId(), $this->userId);
	}

	/**
	 * A category that comes from the domain carries no changes of its own: the fields are filled and
	 * the change marks the setters left are dropped, so a category handed to a write later carries
	 * only what its caller has set.
	 */
	private static function toCategory(CategoryData $data): Category
	{
		$category = (new Category())
			->setId($data->id)
			->setEntityTypeId($data->entityTypeId)
			->setName($data->name)
			->setSort($data->sort)
			->setIsDefault($data->isDefault)
			->setIsSystem($data->isSystem)
			->setCode($data->code)
		;
		$category->resetChangedFields();

		return $category;
	}
}
