<?php

namespace Bitrix\Sign\Service\Sign\SignersList;

use Bitrix\Sign\Access\AccessController;
use Bitrix\Sign\Access\AccessController\AccessControllerFactory;
use Bitrix\Sign\Access\ActionDictionary;
use Bitrix\Sign\Service\SignersListService;

class AccessService
{
	private const LIST_READ_CHUNK_SIZE = 300;

	/** @var array<int, AccessController|null> */
	private array $accessControllers = [];

	public function __construct(
		private readonly SignersListService $signersListService,
		private readonly AccessControllerFactory $accessControllerFactory,
	)
	{
	}

	public function hasAccessToRead(int $listId): bool
	{
		return $this->check($listId, ActionDictionary::ACTION_B2E_SIGNERS_LIST_READ);
	}

	public function hasAccessToEdit(int $listId): bool
	{
		return $this->check($listId, ActionDictionary::ACTION_B2E_SIGNERS_LIST_EDIT);
	}

	public function hasAccessToDelete(int $listId): bool
	{
		return $this->check($listId, ActionDictionary::ACTION_B2E_SIGNERS_LIST_DELETE);
	}

	public function getAccessibleList(int $listId, string $action): ?\Bitrix\Sign\Item\SignersList
	{
		return $this->resolve($listId, $action, true);
	}

	/**
	 * Batch counterpart of getAccessibleList() for id sets that come from a request payload:
	 * the lists are read in chunks instead of one query per id. The rules are the same ones —
	 * the rejected list bypass and the item-aware owner scope still decide per list.
	 *
	 * @param int[] $listIds
	 *
	 * @return array<int, \Bitrix\Sign\Item\SignersList> accessible lists keyed by id
	 */
	public function getAccessibleLists(array $listIds, string $action): array
	{
		$ids = [];
		foreach ($listIds as $listId)
		{
			if ($listId >= 1)
			{
				$ids[$listId] = $listId;
			}
		}

		if ($ids === [])
		{
			return [];
		}

		$accessController = $this->getAccessController();
		if ($accessController === null)
		{
			return [];
		}

		$accessibleLists = [];
		foreach (array_chunk(array_values($ids), self::LIST_READ_CHUNK_SIZE) as $idsChunk)
		{
			foreach ($this->signersListService->listByIds($idsChunk) as $list)
			{
				if ($list->id !== null && $this->isListAccessible($list, $action, $accessController))
				{
					$accessibleLists[$list->id] = $list;
				}
			}
		}

		return $accessibleLists;
	}

	private function check(int $listId, string $action): bool
	{
		return $this->resolve($listId, $action, false) === true;
	}

	/**
	 * @return \Bitrix\Sign\Item\SignersList|true|null
	 */
	private function resolve(int $listId, string $action, bool $shouldReturnItem): \Bitrix\Sign\Item\SignersList|true|null
	{
		if ($listId < 1)
		{
			return null;
		}

		$accessController = $this->getAccessController();
		if ($accessController === null)
		{
			return null;
		}

		if ($this->signersListService->isRejectedList($listId))
		{
			if (!$accessController->check(ActionDictionary::ACTION_B2E_SIGNERS_LIST_REFUSED_EDIT))
			{
				return null;
			}

			return $shouldReturnItem ? $this->signersListService->getById($listId) : true;
		}

		$list = $this->signersListService->getById($listId);
		if ($list === null)
		{
			return null;
		}

		if (!$accessController->checkByItem($action, $list))
		{
			return null;
		}

		return $shouldReturnItem ? $list : true;
	}

	private function isListAccessible(
		\Bitrix\Sign\Item\SignersList $list,
		string $action,
		AccessController $accessController,
	): bool
	{
		if ($this->signersListService->isRejectedList((int)$list->id))
		{
			return $accessController->check(ActionDictionary::ACTION_B2E_SIGNERS_LIST_REFUSED_EDIT);
		}

		return $accessController->checkByItem($action, $list);
	}

	private function getAccessController(): ?AccessController
	{
		// Keyed by user id: the current user can change inside one process, and the service is a
		// per-request singleton. A null controller is memoized too, so a userId < 1 does not send
		// the factory a request per call.
		$userId = (int)\Bitrix\Main\Engine\CurrentUser::get()->getId();
		if (!array_key_exists($userId, $this->accessControllers))
		{
			$this->accessControllers[$userId] = $this->accessControllerFactory->createByUserId($userId);
		}

		return $this->accessControllers[$userId];
	}
}
