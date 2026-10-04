<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Category;

use Bitrix\Crm\V2\Internal\Entity\Category\StageData;
use Bitrix\Crm\V2\Internal\Repository\Category\StageRepository;
use Bitrix\Crm\V2\Internal\Repository\Category\StageRepositoryInterface;
use Bitrix\Main\Provider\Params\PagerInterface;

/**
 * Reads the stages of one category, one page at a time.
 *
 * A category the user may not read yields an empty set, the same as a category without stages and
 * as a category that does not exist: the three are told apart by the caller, through
 * {@see CategoryAccess::canRead()} and
 * {@see \Bitrix\Crm\V2\Internal\Repository\Category\CategoryRepositoryInterface::getById()}.
 *
 * Stages always belong to a category here. Lead and Quote have stages without having categories,
 * so they have nothing this reader can read and get an empty set.
 *
 * @internal
 */
class StageReader
{
	public function __construct(
		private readonly CategoryAccess $access = new CategoryAccess(),
	)
	{
	}

	/**
	 * One page of the stages of $categoryId, in the order the repository establishes (`sort`, then
	 * the stage record id). Without a $pager the whole set of the category is returned.
	 *
	 * @return StageData[]
	 */
	public function getList(int $entityTypeId, int $categoryId, int $userId, ?PagerInterface $pager = null): array
	{
		if (!$this->access->canRead($entityTypeId, $userId, $categoryId))
		{
			return [];
		}

		$stages = $this->createRepository($entityTypeId)->getAllByCategory($categoryId);
		if ($pager === null)
		{
			return $stages;
		}

		return array_slice($stages, $pager->getOffset(), $pager->getLimit());
	}

	protected function createRepository(int $entityTypeId): StageRepositoryInterface
	{
		return new StageRepository($entityTypeId);
	}
}
