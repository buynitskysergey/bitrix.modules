<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Repository\Mapper;

use Bitrix\Bizproc\Internal\Entity\AiAgent\ManagedAgentResource;
use Bitrix\Bizproc\Internal\Entity\AiAgent\ManagedAgentResourceType;
use Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentResource;
use Bitrix\Bizproc\Internal\Model\AiAgent\ManagedAgentResourceTable;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Main\Type\DateTime;

/**
 * Converts a resource ownership row into the entity and back.
 *
 * The DATA column keeps the versioned JSON payload of the resource type, while the entity works with the
 * decoded array. Payload contents never reach an exception message or a log record: only the column name and
 * the reason of the failure are reported.
 */
class ManagedAgentResourceMapper
{
	private const DATA_FIELD_NAME = 'DATA';

	public function convertFromOrm(EO_ManagedAgentResource $ormModel): ManagedAgentResource
	{
		return new ManagedAgentResource(
			id: $ormModel->getId(),
			instanceId: (int)$ormModel->getInstanceId(),
			type: ManagedAgentResourceType::from((string)$ormModel->getType()),
			resourceId: (string)$ormModel->getResourceId(),
			data: $this->decodeData($ormModel->getData()),
			createdAt: $ormModel->getCreatedAt(),
		);
	}

	public function convertToOrm(ManagedAgentResource $entity): EO_ManagedAgentResource
	{
		$ormModel = $entity->isNew()
			? ManagedAgentResourceTable::createObject()
			: EO_ManagedAgentResource::wakeUp($entity->getId())
		;

		if ($entity->isNew())
		{
			$ormModel
				->setInstanceId($entity->getInstanceId())
				->setType($entity->getType()->value)
				->setResourceId($entity->getResourceId())
				->setCreatedAt($entity->getCreatedAt() ?? new DateTime())
			;
		}

		$ormModel->setData($entity->getData() === [] ? null : $this->encodeData($entity->getData()));

		return $ormModel;
	}

	private function decodeData(?string $raw): array
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
			throw new PersistenceException(
				'Unable to read ' . self::DATA_FIELD_NAME . ' of a managed agent resource: '
				. $exception->getMessage(),
				$exception
			);
		}

		if (!is_array($decoded))
		{
			throw new PersistenceException(
				'Unable to read ' . self::DATA_FIELD_NAME . ' of a managed agent resource: expected a JSON array, got '
				. get_debug_type($decoded)
			);
		}

		return $decoded;
	}

	private function encodeData(array $data): string
	{
		try
		{
			return json_encode(
				$data,
				JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			);
		}
		catch (\JsonException $exception)
		{
			throw new PersistenceException(
				'Unable to write ' . self::DATA_FIELD_NAME . ' of a managed agent resource: '
				. $exception->getMessage(),
				$exception
			);
		}
	}
}
