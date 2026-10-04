<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Command\DataView;

use Bitrix\Bizproc\Internal\Container;
use Bitrix\Bizproc\Internal\Exception\DataView\InvalidDataViewDefinitionException;
use Bitrix\Bizproc\Internal\Repository\DataViewRepository\DataViewRepositoryInterface;
use Bitrix\Bizproc\Public\Command\StorageType\DeleteStorageTypeCommand;
use Bitrix\Bizproc\Public\Command\StorageType\DeleteStorageTypeCommandHandler;
use Bitrix\Bizproc\Public\DataView\Exception\RecomputeInProgressException;

final class DeleteDataViewCommandHandler
{
	private DataViewRepositoryInterface $dataViewRepository;

	public function __construct()
	{
		$this->dataViewRepository = Container::getDataViewRepository();
	}

	public function __invoke(DeleteDataViewCommand $command): void
	{
		$view = $this->dataViewRepository->getByStorageTypeId($command->storageTypeId);
		if ($view === null)
		{
			throw new InvalidDataViewDefinitionException('Storage type is not a data view.');
		}

		if (!$this->dataViewRepository->acquireViewLock($command->storageTypeId))
		{
			throw RecomputeInProgressException::forStorageType($command->storageTypeId);
		}

		try
		{
			(new DeleteStorageTypeCommandHandler())(
				new DeleteStorageTypeCommand($command->storageTypeId)
			);
		}
		finally
		{
			$this->dataViewRepository->releaseViewLock($command->storageTypeId);
		}
	}
}
