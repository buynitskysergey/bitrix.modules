<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\DataView;

use Bitrix\Bizproc\Internal\Entity\DataView\DataView;
use Bitrix\Bizproc\Internal\Repository\DataViewRepository\DataViewRepositoryInterface;
use Bitrix\Bizproc\Internal\Repository\StorageItemRepository\StorageItemRepositoryInterface;
use Bitrix\Bizproc\Internal\Service\Storage\StorageLimitsService;
use Bitrix\Bizproc\Public\DataView\Exception\RecomputeInProgressException;
use Bitrix\Bizproc\Public\DataView\Exception\RowLimitExceededException;
use Bitrix\Bizproc\Public\DataView\Exception\SourceUnavailableException;
use Bitrix\Main\Application;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;

class MaterializeService
{
	private const LOCK_PREFIX = 'bizproc_dataview_';

	private array $inProgressStorageTypeIds = [];

	public function __construct(
		private readonly CombineEngine $combineEngine,
		private readonly DataViewRepositoryInterface $dataViewRepository,
		private readonly StorageItemRepositoryInterface $itemRepository,
		private readonly StorageLimitsService $limitsService,
	) {
	}

	public function materialize(DataView $view, int $actorId): int
	{
		$storageTypeId = $view->getStorageTypeId();
		if (isset($this->inProgressStorageTypeIds[$storageTypeId]))
		{
			throw RecomputeInProgressException::forStorageType($storageTypeId);
		}

		$this->inProgressStorageTypeIds[$storageTypeId] = true;
		try
		{
			return $this->doMaterialize($view, $actorId);
		}
		finally
		{
			unset($this->inProgressStorageTypeIds[$storageTypeId]);
		}
	}

	private function doMaterialize(DataView $view, int $actorId): int
	{
		if ($this->limitsService->shouldBlockWrite())
		{
			throw new SystemException('DataView materialization aborted: disk write limit is reached.');
		}

		$lockName = self::LOCK_PREFIX . $view->getStorageTypeId();
		if (!$this->acquireLock($lockName))
		{
			throw RecomputeInProgressException::forStorageType($view->getStorageTypeId());
		}

		try
		{
			$fresh = $this->reloadUnderLock($view);
			if ($fresh === null)
			{
				return 0;
			}

			$view = $fresh;

			try
			{
				$result = $this->combineEngine->combine($view, $actorId);
			}
			catch (SourceUnavailableException|RowLimitExceededException $exception)
			{
				$this->dataViewRepository->markBroken($view, $exception->getMessage());
				throw $exception;
			}

			$connection = Application::getConnection();
			try
			{
				$connection->startTransaction();
				$this->itemRepository->replaceDataViewItems($view, $result->getRows(), $actorId);
				$this->dataViewRepository->markActual($view, new DateTime(), $actorId);
				$connection->commitTransaction();
			}
			catch (\Throwable $exception)
			{
				$connection->rollbackTransaction();
				throw $exception;
			}
		}
		finally
		{
			$this->releaseLock($lockName);
		}

		return $result->count();
	}

	private function reloadUnderLock(DataView $view): ?DataView
	{
		return $this->dataViewRepository->getByStorageTypeId($view->getStorageTypeId());
	}

	protected function acquireLock(string $lockName): bool
	{
		return Application::getConnection()->lock($lockName, 0);
	}

	protected function releaseLock(string $lockName): void
	{
		Application::getConnection()->unlock($lockName);
	}
}
