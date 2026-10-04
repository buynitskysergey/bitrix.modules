<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Loader;

use Bitrix\Crm\V2\Internal\Repository\User\EmployeeRepository;
use Bitrix\Crm\V2\Public\Entity\Item\Interface\HasStagesInterface;
use Bitrix\Crm\V2\Public\Entity\Item\Item;
use Bitrix\Crm\V2\Public\Provider\Item\Param\ItemSelect;

final class EmployeeLoader
{
	public function __construct(
		private readonly EmployeeRepository $employeeRepository = new EmployeeRepository(),
	)
	{
	}

	/**
	 * @param Item[] $items
	 */
	public function load(array $items, ItemSelect $select): void
	{
		if (!$select->shouldLoadEmployee())
		{
			return;
		}

		$userIds = [];
		foreach ($items as $item)
		{
			$itemUserIds = [
				$item->getCreatedById(),
				$item->getUpdatedById(),
				$item->getAssignedById(),
				$item->getLastActivityById(),
			];
			if ($item instanceof HasStagesInterface)
			{
				$itemUserIds[] = $item->getMovedById();
			}
			if ($item->getObservers() !== null)
			{
				$itemUserIds = array_merge($itemUserIds, $item->getObservers());
			}

			array_push(
				$userIds,
				...array_filter(array_unique($itemUserIds)),
			);
		}

		$employeesById = $this->loadByIds($userIds);
		foreach ($items as $item)
		{
			$userId = $item->getCreatedById();
			if ($userId !== null)
			{
				$item->internalSet(Item::createdBy, $employeesById[$userId] ?? null);
			}

			$userId = $item->getUpdatedById();
			if ($userId !== null)
			{
				$item->internalSet(Item::updatedBy, $employeesById[$userId] ?? null);
			}

			$userId = $item->getLastActivityById();
			if ($userId !== null)
			{
				$item->internalSet(Item::lastActivityBy, $employeesById[$userId] ?? null);
			}

			$userId = $item->getAssignedById();
			if ($userId !== null)
			{
				$item->internalSet(Item::assignedBy, $employeesById[$userId] ?? null);
			}

			if ($item instanceof HasStagesInterface)
			{
				$userId = $item->getMovedById();
				if ($userId !== null)
				{
					$item->internalSet(Item::movedBy, $employeesById[$userId] ?? null);
				}
			}

			$observerIds = $item->getObservers();
			if ($observerIds !== null)
			{
				$observerEmployees = [];
				foreach ($observerIds as $observerId)
				{
					if (isset($employeesById[$observerId]))
					{
						$observerEmployees[] = $employeesById[$observerId];
					}
				}
				$item->internalSet(Item::observersEmployees, $observerEmployees);
			}
		}
	}

	public function loadByIds(array $userIds): array
	{
		$userIds = array_values(array_filter(array_unique($userIds)));
		if (empty($userIds))
		{
			return [];
		}

		return $this->employeeRepository->getByIds($userIds);
	}
}
