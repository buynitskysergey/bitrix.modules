<?php

namespace Bitrix\Disk\Security;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Driver;
use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkAccessLevel;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkAccessService;
use Bitrix\Disk\Internals\ObjectPathTable;
use Bitrix\Disk\Internals\RightTable;
use Bitrix\Disk\Internals\SimpleRightTable;
use Bitrix\Disk\RightsManager;
use Bitrix\Disk\Access\AccessCodeEnum;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\UserAccessTable;
use CAccess;

class DiskSecurityContext extends SecurityContext
{
	protected UnifiedLinkAccessService $unifiedLinkAccessService;
	protected bool $shouldCheckUnifiedLink = false;
	/** @var  array */
	protected $operationsCache = array();
	protected array $unifiedLinkCache = [];

	public function __construct($user)
	{
		parent::__construct($user);

		$this->unifiedLinkAccessService = ServiceLocator::getInstance()->get(UnifiedLinkAccessService::class);
	}

	public function checkUnifiedLink(bool $shouldCheckUnifiedLink): void
	{
		$this->shouldCheckUnifiedLink = $shouldCheckUnifiedLink;
	}

	/**
	 * @param $objectId
	 * @param $operation
	 * @return bool
	 */
	protected function canDoOperation($objectId, $operation)
	{
		if ($this->userId === static::GUEST_USER)
		{
			return false;
		}

		if ($this->shouldCheckUnifiedLink && $this->canDoOperationByUnifiedLink($objectId, $operation))
		{
			return true;
		}

		if (!isset($this->operationsCache[$objectId]))
		{
			$this->operationsCache[$objectId] = $this->getOperationsByObject($objectId);
		}

		return isset($this->operationsCache[$objectId][$operation]);
	}

	protected function canDoOperationByUnifiedLink(mixed $objectId, string $operation): bool
	{
		$accessLevels = Driver::getInstance()->getRightsManager()->getUnifiedLinkAccessLevelsByOperation(
			operation: $operation,
		);

		if (empty($accessLevels))
		{
			return false;
		}

		if (!array_key_exists($objectId, $this->unifiedLinkCache))
		{
			$object = $this->getObject($objectId);

			if (!$object instanceof BaseObject)
			{
				$accessLevel = null;
			}
			else
			{
				$accessLevel = $this->unifiedLinkAccessService->check(
					baseObject: $object,
					userId: (int)$this->userId,
				);
			}

			$this->unifiedLinkCache[$objectId] = $accessLevel;
		}

		$accessLevel = $this->unifiedLinkCache[$objectId];

		if (!$accessLevel instanceof UnifiedLinkAccessLevel)
		{
			return false;
		}

		return in_array($accessLevel->value, $accessLevels, true);
	}

	protected function getOperationsByObject($objectId)
	{
		$access = new CAccess;
		$access->updateCodes(array('USER_ID' => $this->userId));

		return Driver::getInstance()->getRightsManager()->getUserOperationsByObject($objectId, $this->userId);
	}

	public function preloadOperationsForChildren($parentObjectId): void
	{
		$rightsManager = Driver::getInstance()->getRightsManager();

		$objectIds = [];

		foreach ($rightsManager->getUserOperationsForChildren($parentObjectId, $this->userId) as $objectId => $operations)
		{
			$this->operationsCache[$objectId] = $operations;
			$objectIds[] = (int)$objectId;
		}

		unset($operations);

		$this->preloadUnifiedLinkAccessLevels($objectIds);
	}

	public function preloadOperationsForSpecifiedObjects($parentObjectId, array $objectIds): void
	{
		$rightsManager = Driver::getInstance()->getRightsManager();

		$operationsByObjectId = $rightsManager->getUserOperationsForChildren(
			parentId: $parentObjectId,
			userId: $this->userId,
			restrictIds: $objectIds,
		);

		foreach ($operationsByObjectId as $objectId => $operations)
		{
			$this->operationsCache[$objectId] = $operations;
		}

		$this->preloadUnifiedLinkAccessLevels($objectIds);
	}

	protected function preloadUnifiedLinkAccessLevels(array $objectIds): void
	{
		if (!$this->shouldCheckUnifiedLink || $this->userId === static::GUEST_USER)
		{
			return;
		}

		$objectIds = array_values(array_unique(array_filter(array_map('intval', $objectIds))));

		if (empty($objectIds))
		{
			return;
		}

		$objects = [];

		foreach (BaseObject::loadBatchById($objectIds) as $object)
		{
			if ($object instanceof BaseObject)
			{
				$objects[(int)$object->getId()] = $object;
			}
		}

		if (empty($objects))
		{
			foreach ($objectIds as $objectId)
			{
				$this->unifiedLinkCache[$objectId] = null;
			}

			return;
		}

		$this->preloadUnifiedLinkAccessLevelsByObjects($objects, $objectIds);
	}

	/**
	 * @param array<int, BaseObject> $objects
	 * @return void
	 */
	public function preloadUnifiedLinkAccessLevelsForObjects(array $objects): void
	{
		if (!$this->shouldCheckUnifiedLink || $this->userId === static::GUEST_USER)
		{
			return;
		}

		$baseObjects = [];

		foreach ($objects as $object)
		{
			if ($object instanceof BaseObject)
			{
				$baseObjects[(int)$object->getId()] = $object;
			}
		}

		if (empty($baseObjects))
		{
			return;
		}

		$this->preloadUnifiedLinkAccessLevelsByObjects($baseObjects, array_keys($baseObjects));
	}

	/**
	 * @param array<int, BaseObject> $baseObjects
	 * @param array<int, int> $objectIds
	 * @return void
	 */
	protected function preloadUnifiedLinkAccessLevelsByObjects(array $baseObjects, array $objectIds): void
	{
		$accessLevelsByObjectId = $this->unifiedLinkAccessService->checkCollection(
			baseObjects: $baseObjects,
			userId: (int)$this->userId,
			operationsByObjectId: $this->operationsCache,
		);

		foreach ($objectIds as $objectId)
		{
			$this->unifiedLinkCache[$objectId] = $accessLevelsByObjectId[$objectId] ?? null;
		}
	}

	/**
	 * @param $targetId
	 * @return bool
	 */
	public function canAdd($targetId)
	{
		return $this->canDoOperation($targetId, RightsManager::OP_ADD);
	}

	/**
	 * @param $objectId
	 * @return bool
	 */
	public function canChangeRights($objectId)
	{
		return $this->canDoOperation($objectId, RightsManager::OP_RIGHTS);
	}

	/**
	 * @param $objectId
	 * @return bool
	 */
	public function canChangeSettings($objectId)
	{
		return $this->canDoOperation($objectId, RightsManager::OP_SETTINGS);
	}

	/**
	 * @param $objectId
	 * @return bool
	 */
	public function canCreateWorkflow($objectId)
	{
		return $this->canDoOperation($objectId, RightsManager::OP_CREATE_WF);
	}

	/**
	 * @param $objectId
	 * @return bool
	 */
	public function canDelete($objectId)
	{
		return $this->canDoOperation($objectId, RightsManager::OP_DESTROY);
	}

	/**
	 * @param $objectId
	 * @return bool
	 */
	public function canMarkDeleted($objectId)
	{
		return $this->canDoOperation($objectId, RightsManager::OP_DELETE);
	}

	/**
	 * @param $objectId
	 * @param $targetId
	 * @return bool
	 */
	public function canMove($objectId, $targetId)
	{
		return $this->canRead($objectId) && $this->canMarkDeleted($objectId) && $this->canAdd($targetId);
	}

	/**
	 * @param $objectId
	 * @return bool
	 */
	public function canRead($objectId)
	{
		return $this->canDoOperation($objectId, RightsManager::OP_READ);
	}

	/**
	 * @param $objectId
	 * @return bool
	 */
	public function canRename($objectId)
	{
		return $this->canDoOperation($objectId, RightsManager::OP_EDIT);
	}

	/**
	 * @param $objectId
	 * @return bool
	 */
	public function canRestore($objectId)
	{
		return $this->canDoOperation($objectId, RightsManager::OP_RESTORE);
	}

	/**
	 * @param $objectId
	 * @return bool
	 */
	public function canShare($objectId)
	{
		return $this->canDoOperation($objectId, RightsManager::OP_SHARING);
	}

	/**
	 * @param $objectId
	 * @return bool
	 */
	public function canUpdate($objectId)
	{
		return $this->canDoOperation($objectId, RightsManager::OP_EDIT);
	}

	/**
	 * @param $objectId
	 * @return bool
	 */
	public function canStartBizProc($objectId)
	{
		return $this->canDoOperation($objectId, RightsManager::OP_START_BP);
	}

	public function getSqlExpressionForList($columnObjectId, $columnCreatedBy)
	{
		$readableTaskIdsSql = $this->getReadableTaskIdsSql();
		if ($readableTaskIdsSql === null)
		{
			return '1 = 0';
		}

		$tableDiskSimpleRight = SimpleRightTable::getTableName();
		$tableDiskRight = RightTable::getTableName();
		$tableObjectPath = ObjectPathTable::getTableName();

		$accessCodePredicate = $this->buildAccessCodePredicate($columnCreatedBy, 3);

		return  <<<SQL
			EXISTS (
				SELECT 1
				FROM $tableDiskSimpleRight simple_right
				WHERE simple_right.OBJECT_ID = (
					SELECT path.PARENT_ID
					FROM $tableObjectPath path
					WHERE path.OBJECT_ID = $columnObjectId
					  AND EXISTS (
						  SELECT 1
						  FROM $tableDiskRight readable_right
						  WHERE readable_right.OBJECT_ID = path.PARENT_ID
							AND readable_right.TASK_ID IN ($readableTaskIdsSql)
					  )
					ORDER BY path.DEPTH_LEVEL ASC
					LIMIT 1
				)
				  AND (
		$accessCodePredicate
				  )
			)
		SQL;
	}

	public function getSqlExpressionForRootObjectList(string $columnObjectId, string $columnCreatedBy): string
	{
		$readableTaskIdsSql = $this->getReadableTaskIdsSql();
		if ($readableTaskIdsSql === null)
		{
			return '1 = 0';
		}

		$tableDiskSimpleRight = SimpleRightTable::getTableName();
		$tableDiskRight = RightTable::getTableName();

		$accessCodePredicate = $this->buildAccessCodePredicate($columnCreatedBy, 4);

		return <<<SQL
			(
				EXISTS (
					SELECT 1
					FROM $tableDiskRight readable_right
					WHERE readable_right.OBJECT_ID = $columnObjectId
					  AND readable_right.TASK_ID IN ($readableTaskIdsSql)
				)
				AND EXISTS (
					SELECT 1
					FROM $tableDiskSimpleRight simple_right
					WHERE simple_right.OBJECT_ID = $columnObjectId
					  AND (
		$accessCodePredicate
					  )
				)
			)
		SQL;
	}

	/**
	 * Access code rule shared by every rights expression of the module.
	 * $indentLevel aligns the predicate with the SQL block it is embedded into.
	 */
	private function buildAccessCodePredicate(string $columnCreatedBy, int $indentLevel): string
	{
		$userId = (int)$this->userId;
		$tableUserAccess = UserAccessTable::getTableName();

		$accessCodeCreator = AccessCodeEnum::CREATOR->value;
		$accessCodeAuthorizedUser = AccessCodeEnum::AUTHORIZED_USER->value;
		$indent = str_repeat("\t", $indentLevel);

		return <<<SQL
		{$indent}  (simple_right.ACCESS_CODE = '$accessCodeCreator' AND $columnCreatedBy = $userId)

		{$indent}  OR simple_right.ACCESS_CODE = '$accessCodeAuthorizedUser'

		{$indent}  OR simple_right.ACCESS_CODE IN (
		{$indent}	  SELECT ACCESS_CODE
		{$indent}	  FROM $tableUserAccess
		{$indent}	  WHERE USER_ID = $userId
		{$indent}  )
		SQL;
	}

	private function getReadableTaskIdsSql(): ?string
	{
		if ($this->userId === static::GUEST_USER)
		{
			return null;
		}

		$readableTaskIds = Driver::getInstance()->getRightsManager()->getReadableTaskIds();
		if (empty($readableTaskIds))
		{
			return null;
		}

		return implode(', ', array_map('intval', $readableTaskIds));
	}

	protected function getObject(mixed $objectId): ?BaseObject
	{
		return BaseObject::getById($objectId);
	}
}
