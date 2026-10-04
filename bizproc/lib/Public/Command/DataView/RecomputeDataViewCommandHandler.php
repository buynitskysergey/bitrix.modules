<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Command\DataView;

use Bitrix\Bizproc\Internal\Container;
use Bitrix\Bizproc\Internal\Entity\DataView\DataView;
use Bitrix\Bizproc\Internal\Repository\DataViewRepository\DataViewRepositoryInterface;
use Bitrix\Bizproc\Internal\Service\DataView\ColumnResolver;
use Bitrix\Bizproc\Internal\Service\DataView\MaterializeService;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\UserTable;

final class RecomputeDataViewCommandHandler
{
	private MaterializeService $materializeService;
	private DataViewRepositoryInterface $dataViewRepository;

	public function __construct()
	{
		$this->materializeService = ServiceLocator::getInstance()->get('bizproc.service.dataView.materializeService');
		$this->dataViewRepository = Container::getDataViewRepository();
	}

	public function __invoke(RecomputeDataViewCommand $command): int
	{
		$view = $this->dataViewRepository->getByStorageTypeId($command->storageTypeId);
		if ($view === null)
		{
			return 0;
		}

		if (!ColumnResolver::isSupportedVersion($view->getDefinition()))
		{
			$this->dataViewRepository->markBroken(
				$view,
				(string)Loc::getMessage(
					'BIZPROC_PUBLIC_COMMAND_DATAVIEW_RECOMPUTE_UNSUPPORTED_VERSION',
					['#VERSION#' => (string)($view->getDefinition()['version'] ?? '')],
				),
			);

			return 0;
		}

		$actorId = $this->resolveActorId($command, $view);
		if ($actorId === null)
		{
			$this->dataViewRepository->markBroken(
				$view,
				(string)Loc::getMessage('BIZPROC_PUBLIC_COMMAND_DATAVIEW_RECOMPUTE_ACTOR_UNAVAILABLE'),
			);

			return 0;
		}

		return $this->materializeService->materialize($view, $actorId);
	}

	private function resolveActorId(RecomputeDataViewCommand $command, DataView $view): ?int
	{
		$actorId = (int)($command->actorId ?? $view->getUpdatedBy() ?? $view->getCreatedBy());

		return $actorId > 0 && $this->isActorActive($actorId) ? $actorId : null;
	}

	private function isActorActive(int $actorId): bool
	{
		return UserTable::getRow([
			'select' => ['ID'],
			'filter' => ['=ID' => $actorId, '=ACTIVE' => 'Y'],
		]) !== null;
	}
}
