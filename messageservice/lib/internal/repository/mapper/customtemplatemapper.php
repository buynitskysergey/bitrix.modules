<?php

namespace Bitrix\MessageService\Internal\Repository\Mapper;

use Bitrix\MessageService\Internal\Entity\CustomTemplate;
use Bitrix\MessageService\Internal\Entity\CustomTemplateTable;
use Bitrix\MessageService\Internal\Entity\EO_CustomTemplate;
use Bitrix\MessageService\Internal\ValueObject\CustomTemplateBinding;

final class CustomTemplateMapper
{
	public function convertFromOrm(EO_CustomTemplate $ormModel): CustomTemplate
	{
		$entity = new CustomTemplate(
			new CustomTemplateBinding(
				(string)$ormModel->getZone(),
				(string)$ormModel->getScene(),
				(string)$ormModel->getTargetId(),
			),
			(string)$ormModel->getTitle(),
			(string)$ormModel->getBody(),
		);
		$entity
			->setId((int)$ormModel->getId())
			->setDateCreate($ormModel->getDateCreate())
			->setAuthorId((int)$ormModel->getAuthorId())
			->setDateModify($ormModel->getDateModify())
			->setModifiedBy($ormModel->getModifiedBy() !== null ? (int)$ormModel->getModifiedBy() : null);

		return $entity;
	}

	public function convertToOrm(CustomTemplate $entity): EO_CustomTemplate
	{
		$ormModel = $entity->getId()
			? EO_CustomTemplate::wakeUp($entity->getId())
			: CustomTemplateTable::createObject();

		$binding = $entity->getBinding();
		$ormModel
			->setZone($binding->zone)
			->setScene($binding->scene)
			->setTargetId($binding->targetId)
			->setTitle($entity->getTitle())
			->setBody($entity->getBody());

		if ($entity->getDateCreate())
		{
			$ormModel->setDateCreate($entity->getDateCreate());
		}
		if ($entity->getAuthorId() !== null)
		{
			$ormModel->setAuthorId($entity->getAuthorId());
		}
		if ($entity->getDateModify())
		{
			$ormModel->setDateModify($entity->getDateModify());
		}
		if ($entity->getModifiedBy() !== null)
		{
			$ormModel->setModifiedBy($entity->getModifiedBy());
		}

		return $ormModel;
	}
}
