<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Command\StorageType;

use Bitrix\Bizproc\Internal\Container;
use Bitrix\Bizproc\Internal\Repository\StorageTypeRepository\StorageTypeRepositoryInterface;
use Bitrix\Bizproc\Internal\Repository\StorageFieldRepository\StorageFieldRepositoryInterface;
use Bitrix\Bizproc\Internal\Repository\DataViewRepository\DataViewRepositoryInterface;
use Bitrix\Bizproc\Internal\Exception\StorageType\DeleteStorageTypeException;
use Bitrix\Bizproc\Infrastructure\Stepper\StorageItemDeleteStepper;
use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\Localization\Loc;

class DeleteStorageTypeCommandHandler
{
	private StorageTypeRepositoryInterface $repository;
	private StorageFieldRepositoryInterface $fieldRepository;
	private ?DataViewRepositoryInterface $dataViewRepository;
	private Connection $connection;

	public function __construct()
	{
		$this->connection = Application::getConnection();
		$this->repository = Container::getStorageTypeRepository();
		$this->fieldRepository = Container::getStorageFieldRepository();
		$this->dataViewRepository = Container::getDataViewRepository();
	}

	public function __invoke(DeleteStorageTypeCommand $command): void
	{
		$existStorageType = $this->repository->exists($command->id);
		if (!$existStorageType)
		{
			throw new DeleteStorageTypeException('Storage type not found');
		}

		if (StorageItemDeleteStepper::hasAgentsForStorage($command->id))
		{
			throw new DeleteStorageTypeException(
				Loc::getMessage('BIZPROC_STORAGE_TYPE_DELETE_ITEMS_DELETION_IN_PROGRESS') ?? ''
			);
		}

		$hasDataView = $this->dataViewRepository?->getByStorageTypeId($command->id) !== null;
		if ($hasDataView && !$this->dataViewRepository->acquireViewLock($command->id))
		{
			throw new DeleteStorageTypeException(
				sprintf('Unable to acquire data view lock for storage type %d.', $command->id)
			);
		}

		try
		{
			$this->connection->startTransaction();

			try
			{
				$this->fieldRepository->deleteByStorageId($command->id);
				$this->dataViewRepository?->deleteByStorageTypeId($command->id);
				$this->repository->delete($command->id);
			}
			catch (\Throwable $exception)
			{
				$this->connection->rollbackTransaction();
				throw new DeleteStorageTypeException($exception->getMessage());
			}

			$this->connection->commitTransaction();
		}
		finally
		{
			if ($hasDataView)
			{
				$this->dataViewRepository->releaseViewLock($command->id);
			}
		}

		StorageItemDeleteStepper::bindStorage($command->id);
	}
}
