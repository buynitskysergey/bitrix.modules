<?php

namespace Bitrix\Bizproc\Public\Command\StorageField;

use Bitrix\Bizproc\Infrastructure\Stepper\StorageItemDeleteStepper;
use Bitrix\Bizproc\Internal\Exception\StorageField\DeleteStorageFieldException;
use Bitrix\Bizproc\Internal\Container;
use Bitrix\Bizproc\Internal\Repository\StorageFieldRepository\StorageFieldRepositoryInterface;
use Bitrix\Main\Localization\Loc;

class DeleteStorageFieldCommandHandler
{
	private StorageFieldRepositoryInterface $repository;

	public function __construct()
	{
		$this->repository = Container::getStorageFieldRepository();
	}

	public function __invoke(DeleteStorageFieldCommand $command): void
	{
		$storageField = $this->repository->getById($command->id, ['ID', 'STORAGE_ID']);
		if (!$storageField)
		{
			throw new DeleteStorageFieldException('Storage field not found');
		}

		if (StorageItemDeleteStepper::hasAgentsForStorage((int)$storageField->getStorageId()))
		{
			throw new DeleteStorageFieldException(
				Loc::getMessage('BIZPROC_STORAGE_FIELD_DELETE_ITEMS_DELETION_IN_PROGRESS') ?? ''
			);
		}

		$this->repository->delete($command->id);
	}
}
