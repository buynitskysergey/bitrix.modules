<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Repository\Mapper;

use Bitrix\Bizproc\Internal\Entity\DataView\DataView;
use Bitrix\Bizproc\Internal\Entity\DataView\DataViewStatus;
use Bitrix\Bizproc\Internal\Exception\DataView\CorruptedDataViewStateException;
use Bitrix\Bizproc\Internal\Model\EO_StorageDataView;
use Bitrix\Bizproc\Internal\Model\StorageDataViewTable;
use Bitrix\Main\Type\DateTime;

class DataViewMapper
{
	public function convertFromOrm(EO_StorageDataView $ormModel): DataView
	{
		return new DataView(
			id: $ormModel->getId(),
			storageTypeId: (int)$ormModel->getStorageTypeId(),
			definition: $this->decodeJsonArray($ormModel->getDefinition(), 'DEFINITION'),
			status: DataViewStatus::fromString($ormModel->getStatus()),
			errorText: $ormModel->getErrorText(),
			deletionMarks: $this->decodeJsonArray($ormModel->getDeletionMarks(), 'DELETION_MARKS'),
			materializedAt: $ormModel->getMaterializedAt()?->getTimestamp(),
			materializedBy: $ormModel->getMaterializedBy(),
			ownerTemplateId: $ormModel->getOwnerTemplateId(),
			ownerActivityName: $ormModel->getOwnerActivityName(),
			createdBy: $ormModel->getCreatedBy(),
			updatedBy: $ormModel->getUpdatedBy(),
			createdAt: $ormModel->getCreatedTime()?->getTimestamp(),
			updatedAt: $ormModel->getUpdatedTime()?->getTimestamp(),
		);
	}

	public function convertToOrm(DataView $entity): EO_StorageDataView
	{
		$ormModel = !$entity->isNew()
			? EO_StorageDataView::wakeUp($entity->getId())
			: StorageDataViewTable::createObject()
		;

		if ($entity->isNew())
		{
			$ormModel
				->setCreatedBy((int)$entity->getCreatedBy())
				->setCreatedTime(new DateTime())
			;
		}

		$ormModel
			->setStorageTypeId($entity->getStorageTypeId())
			->setDefinition($this->encodeJson($entity->getDefinition()))
			->setStatus($entity->getStatus()->value)
			->setErrorText($entity->getErrorText())
			->setDeletionMarks(
				$entity->getDeletionMarks() === [] ? null : $this->encodeJson($entity->getDeletionMarks())
			)
			->setMaterializedAt(
				$entity->getMaterializedAt() !== null
					? DateTime::createFromTimestamp($entity->getMaterializedAt())
					: null
			)
			->setMaterializedBy($entity->getMaterializedBy())
			->setOwnerTemplateId($entity->getOwnerTemplateId())
			->setOwnerActivityName($entity->getOwnerActivityName())
			->setUpdatedBy((int)$entity->getUpdatedBy())
			->setUpdatedTime(new DateTime())
		;

		return $ormModel;
	}

	public static function getFieldsMap(): array
	{
		return [
			'ID' => 'id',
			'STORAGE_TYPE_ID' => 'storageTypeId',
			'DEFINITION' => 'definition',
			'STATUS' => 'status',
			'ERROR_TEXT' => 'errorText',
			'DELETION_MARKS' => 'deletionMarks',
			'MATERIALIZED_AT' => 'materializedAt',
			'MATERIALIZED_BY' => 'materializedBy',
			'OWNER_TEMPLATE_ID' => 'ownerTemplateId',
			'OWNER_ACTIVITY_NAME' => 'ownerActivityName',
			'CREATED_BY' => 'createdBy',
			'UPDATED_BY' => 'updatedBy',
			'CREATED_TIME' => 'createdAt',
			'UPDATED_TIME' => 'updatedAt',
		];
	}

	private function decodeJsonArray(?string $raw, string $fieldName): array
	{
		if ($raw === null || $raw === '')
		{
			return [];
		}

		try
		{
			$decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
		}
		catch (\JsonException $exception)
		{
			throw new CorruptedDataViewStateException($fieldName, $exception->getMessage());
		}

		if (!is_array($decoded))
		{
			throw new CorruptedDataViewStateException($fieldName, 'expected a JSON array, got ' . get_debug_type($decoded));
		}

		return $decoded;
	}

	private function encodeJson(array $value): string
	{
		return (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}
}
