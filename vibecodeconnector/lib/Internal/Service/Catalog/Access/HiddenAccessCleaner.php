<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\Access;

use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItemAccessType;
use Bitrix\Vibecodeconnector\Internal\Integration\Main\AccessCodes;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\AccessRepository;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogItemRepository;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\HiddenRepository;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\ViewedRepository;

final class HiddenAccessCleaner
{
	public function __construct(
		private readonly HiddenRepository $hiddenRepository = new HiddenRepository(),
		private readonly CatalogItemRepository $itemRepository = new CatalogItemRepository(),
		private readonly AccessCodes $accessCodes = new AccessCodes(),
		private readonly ViewedRepository $viewedRepository = new ViewedRepository(),
		private readonly AccessRepository $accessRepository = new AccessRepository(),
	)
	{
	}

	/**
	 * The view mark goes away together with the hidden flag: otherwise an app granted
	 * again would never be new for that user, because the novelty criterion would match
	 * while the viewed predicate kept cutting it off.
	 */
	public function cleanupLostAccess(int $catalogItemId): void
	{
		foreach ($this->hiddenRepository->userIdsForItem($catalogItemId) as $userId)
		{
			if (!$this->itemRepository->isAccessibleToUser(
				$catalogItemId,
				$userId,
				$this->accessCodes->getUserCodes($userId),
			))
			{
				$this->hiddenRepository->delete($userId, $catalogItemId);
			}
		}

		$this->cleanupViewMarks($catalogItemId);
	}

	/**
	 * Unlike the hidden flag, the view mark is written for every user the catalog was shown
	 * to, so the marks are cleaned in one statement instead of a per-user access check.
	 */
	private function cleanupViewMarks(int $catalogItemId): void
	{
		$item = $this->itemRepository->getById($catalogItemId);
		if ($item === null || $item->getAccessType() === CatalogItemAccessType::Public)
		{
			return;
		}

		$this->viewedRepository->deleteForUsersWithoutAccess(
			$catalogItemId,
			$item->getOwnerId(),
			$item->getAccessType() === CatalogItemAccessType::ACL
				? $this->accessRepository->getCodesForItem($catalogItemId)
				: [],
		);
	}
}
