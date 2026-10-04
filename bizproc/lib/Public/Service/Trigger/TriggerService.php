<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Service\Trigger;

use Bitrix\Bizproc\Internal\Repository\Trigger\TriggerRepository;
use Bitrix\Main\DI\ServiceLocator;

class TriggerService
{
	private TriggerRepository $repository;

	public function __construct()
	{
		$this->repository = ServiceLocator::getInstance()->get(TriggerRepository::class);
	}

	/**
	 * @param array{0: string, 1: string, 2: string} $complexType [moduleId, entity, documentType]
	 */
	public function hasStartTrigger(string $triggerType, array $complexType): bool
	{
		if (count($complexType) < 3)
		{
			return false;
		}

		[$moduleId, $entity, $documentType] = $complexType;

		return $this->repository->hasStartTrigger($triggerType, $moduleId, $entity, $documentType);
	}
}
