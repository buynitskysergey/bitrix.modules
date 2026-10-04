<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Repository\Mapper;

use Bitrix\Bizproc\Internal\Entity\AiAgent\ManagedAgentInstance;
use Bitrix\Bizproc\Internal\Entity\AiAgent\ManagedAgentInstanceState;
use Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance;
use Bitrix\Bizproc\Internal\Model\AiAgent\ManagedAgentInstanceTable;
use Bitrix\Main\Type\DateTime;

class ManagedAgentInstanceMapper
{
	public function convertFromOrm(EO_ManagedAgentInstance $ormModel): ManagedAgentInstance
	{
		return new ManagedAgentInstance(
			id: $ormModel->getId(),
			identityHash: (string)$ormModel->getIdentityHash(),
			systemCode: (string)$ormModel->getSystemCode(),
			contextNamespace: (string)$ormModel->getContextNamespace(),
			contextType: (string)$ormModel->getContextType(),
			contextId: (string)$ormModel->getContextId(),
			userId: (int)$ormModel->getUserId(),
			templateId: $ormModel->getTemplateId(),
			state: ManagedAgentInstanceState::from((string)$ormModel->getState()),
			configFingerprint: (string)$ormModel->getConfigFingerprint(),
			retryCount: (int)$ormModel->getRetryCount(),
			nextRetryAt: $ormModel->getNextRetryAt(),
			lastErrorCode: $ormModel->getLastErrorCode(),
			createdAt: $ormModel->getCreatedAt(),
			updatedAt: $ormModel->getUpdatedAt(),
		);
	}

	public function convertToOrm(ManagedAgentInstance $entity): EO_ManagedAgentInstance
	{
		$ormModel = $entity->isNew()
			? ManagedAgentInstanceTable::createObject()
			: EO_ManagedAgentInstance::wakeUp($entity->getId())
		;

		if ($entity->isNew())
		{
			$ormModel
				->setIdentityHash($entity->getIdentityHash())
				->setSystemCode($entity->getSystemCode())
				->setContextNamespace($entity->getContextNamespace())
				->setContextType($entity->getContextType())
				->setContextId($entity->getContextId())
				->setCreatedAt($entity->getCreatedAt() ?? new DateTime())
			;
		}

		// UPDATED_AT is written on every change: the schema has no database-specific trigger behind it
		$ormModel
			->setUserId($entity->getUserId())
			->setTemplateId($entity->getTemplateId())
			->setState($entity->getState()->value)
			->setConfigFingerprint($entity->getConfigFingerprint())
			->setRetryCount($entity->getRetryCount())
			->setNextRetryAt($entity->getNextRetryAt())
			->setLastErrorCode($entity->getLastErrorCode())
			->setUpdatedAt($entity->getUpdatedAt() ?? new DateTime())
		;

		return $ormModel;
	}
}
