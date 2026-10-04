<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Category;

use Bitrix\Crm\V2\Internal\Entity\Category\CategoryData;
use Bitrix\Crm\V2\Internal\Repository\Category\CategoryRepository;
use Bitrix\Crm\V2\Internal\Repository\Category\CategoryRepositoryInterface;
use Bitrix\Main\Provider\Params\PagerInterface;

/**
 * Reads the categories a user may see, one page at a time.
 *
 * The page is cut in memory, unlike every other paginated read of the module. The set of categories
 * can only be read whole: it is cached as a whole, for Deal it carries a virtual category that has
 * no row in any table, and the permission filter has always run in memory
 * ({@see \Bitrix\Crm\Service\UserPermissions\EntityPermissions\Category::filterAvailableForReadingCategories()}).
 *
 * @internal
 */
class CategoryReader
{
	public function __construct(
		private readonly CategoryAccess $access = new CategoryAccess(),
	)
	{
	}

	/**
	 * One page of the categories of $entityTypeId visible to $userId, in the order the repository
	 * establishes (`sort`, then `id`). Without a $pager the whole visible set is returned.
	 *
	 * @return CategoryData[]
	 */
	public function getList(int $entityTypeId, int $userId, ?PagerInterface $pager = null): array
	{
		$visible = $this->getVisible($entityTypeId, $userId);
		if ($pager === null)
		{
			return $visible;
		}

		return array_slice($visible, $pager->getOffset(), $pager->getLimit());
	}

	/**
	 * How many categories of $entityTypeId $userId may see - the size of the same set `getList()`
	 * pages over, so it does not depend on any pager.
	 */
	public function getCount(int $entityTypeId, int $userId): int
	{
		return count($this->getVisible($entityTypeId, $userId));
	}

	protected function createRepository(int $entityTypeId): CategoryRepositoryInterface
	{
		return new CategoryRepository($entityTypeId);
	}

	/**
	 * The whole visible set: every category of the entity type the user is allowed to read, ordering
	 * untouched. Permissions are applied before the page is cut - the other way round the page size
	 * would depend on who reads it.
	 *
	 * @return CategoryData[]
	 */
	private function getVisible(int $entityTypeId, int $userId): array
	{
		$all = $this->createRepository($entityTypeId)->getAll();
		$allowedIds = $this->access->getReadableCategoryIds($entityTypeId, $userId);

		return array_values(array_filter(
			$all,
			static fn (CategoryData $category): bool => in_array($category->id, $allowedIds, true),
		));
	}
}
