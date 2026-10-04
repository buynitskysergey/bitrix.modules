<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Provider;

use Bitrix\Bizproc\Internal\Container;
use Bitrix\Bizproc\Internal\Repository\DataViewRepository\DataViewRepositoryInterface;
use Bitrix\Bizproc\Internal\Repository\StorageItemRepository\StorageItemRepositoryInterface;
use Bitrix\Bizproc\Internal\Repository\StorageTypeRepository\StorageTypeRepositoryInterface;
use Bitrix\Main\Type\DateTime;

final class DataViewProvider
{
	private const QUERY_CHUNK = 300;

	private ?DataViewRepositoryInterface $dataViewRepository;
	private ?StorageTypeRepositoryInterface $storageTypeRepository;
	private ?StorageItemRepositoryInterface $storageItemRepository;

	public function __construct()
	{
		$this->dataViewRepository = Container::getDataViewRepository();
		$this->storageTypeRepository = Container::getStorageTypeRepository();
		$this->storageItemRepository = Container::getStorageItemRepository();
	}

	public function getListByOwner(int $templateId, string $activityName): array
	{
		$views = $this->dataViewRepository?->getSummariesByOwner($templateId, $activityName) ?? [];
		if ($views === [])
		{
			return [];
		}

		$storageTypeIds = [];
		foreach ($views as $view)
		{
			$storageTypeIds[] = $view->storageTypeId;
		}
		$storageTypeIds = array_values(array_unique($storageTypeIds));

		$typesById = $this->loadTypesById($storageTypeIds);
		$countsById = $this->storageItemRepository?->getCountsByStorageTypeIds($storageTypeIds) ?? [];

		$items = [];
		foreach ($views as $view)
		{
			$storageTypeId = $view->storageTypeId;
			$type = $typesById[$storageTypeId] ?? null;

			$items[] = [
				'id' => $view->id,
				'storageTypeId' => $storageTypeId,
				'title' => (string)($type?->getTitle() ?? ''),
				'description' => $type?->getDescription(),
				'status' => $view->status->value,
				'rowsCount' => $countsById[$storageTypeId] ?? 0,
			];
		}

		return $items;
	}

	public function getByStorageTypeId(int $storageTypeId): ?array
	{
		$view = $this->dataViewRepository?->getByStorageTypeId($storageTypeId);
		if ($view === null)
		{
			return null;
		}

		$type = $this->storageTypeRepository?->getById($storageTypeId, ['*']);
		$materializedAt = $view->getMaterializedAt();

		return [
			'id' => $view->getId(),
			'storageTypeId' => $storageTypeId,
			'title' => (string)($type?->getTitle() ?? ''),
			'description' => $type?->getDescription(),
			'definition' => $view->getDefinition(),
			'status' => $view->getStatus()->value,
			'errorText' => $view->getErrorText(),
			'materializedAt' => $materializedAt !== null ? DateTime::createFromTimestamp($materializedAt) : null,
		];
	}

	/**
	 * @param int[] $storageTypeIds
	 * @return array<int, \Bitrix\Bizproc\Internal\Entity\StorageType\StorageType>
	 */
	private function loadTypesById(array $storageTypeIds): array
	{
		if ($this->storageTypeRepository === null)
		{
			return [];
		}

		$typesById = [];
		foreach (array_chunk($storageTypeIds, self::QUERY_CHUNK) as $chunk)
		{
			$collection = $this->storageTypeRepository->getStoragesByFilter(
				['@ID' => $chunk],
				['ID', 'TITLE', 'DESCRIPTION'],
			);

			foreach ($collection as $type)
			{
				$typesById[(int)$type->getId()] = $type;
			}
		}

		return $typesById;
	}
}
