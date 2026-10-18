<?php

namespace Bitrix\Mail\Access;

use Bitrix\Mail\Access\Model\MailboxModel;
use Bitrix\Mail\Helper\MailboxAccess;
use Bitrix\HumanResources\Service\Container;
use Bitrix\Mail\Integration\HumanResources\NodeMemberService;
use Bitrix\Main\Access\AccessibleItem;

final class MailboxAccessController extends BaseMailAccessController
{
	private ?array $batchOwnerIds = null;
	private ?array $batchDepartmentIds = null;
	private array $batchDepartmentOwners = [];

	/** Keeps the normal rules and access events, sharing HR and mailbox access lookups within this batch. */
	public function withMailboxBatch(array $models, callable $check): mixed
	{
		$previous = [$this->batchOwnerIds, $this->batchDepartmentIds, $this->batchDepartmentOwners];
		$this->batchOwnerIds = array_values(array_unique(array_map(
			static fn (MailboxModel $model): int => $model->getOwnerId(),
			$models,
		)));
		$this->batchDepartmentIds = null;
		$this->batchDepartmentOwners = [];
		try
		{
			return MailboxAccess::withMailboxBatch($models, $this->getUser()->getUserId(), $check);
		}
		finally
		{
			[$this->batchOwnerIds, $this->batchDepartmentIds, $this->batchDepartmentOwners] = $previous;
		}
	}

	/** Null means the caller must perform its usual individual lookup. */
	public function getBatchDepartmentAccess(int $ownerId, bool $withSubDepartments): ?bool
	{
		if ($this->batchOwnerIds === null || !in_array($ownerId, $this->batchOwnerIds, true))
		{
			return null;
		}
		$this->batchDepartmentIds ??= Container::getNodeRepository()
			->findAllByUserId($this->getUser()->getUserId())->getIds();
		$key = (int)$withSubDepartments;
		if (!array_key_exists($key, $this->batchDepartmentOwners))
		{
			$this->batchDepartmentOwners[$key] = array_fill_keys(
				$this->batchDepartmentIds === [] ? [] : NodeMemberService::filterUsersByDepartmentIds(
					$this->batchOwnerIds,
					$this->batchDepartmentIds,
					$withSubDepartments,
				),
				true,
			);
		}

		return isset($this->batchDepartmentOwners[$key][$ownerId]);
	}

	protected function loadItem(?int $itemId = null): ?AccessibleItem
	{
		return !is_null($itemId) ? MailboxModel::createFromId($itemId) : null;
	}
}
