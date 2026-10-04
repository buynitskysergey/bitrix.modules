<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\AI\Operation\Handler;

use Bitrix\Crm\Copilot\AiCallScriptSelection\Entity\AiCallScriptSelectionTable;
use Bitrix\Crm\Copilot\CallAssessment\Controller\CopilotCallAssessmentController;
use Bitrix\Crm\Copilot\CallScriptMaintenance\Config;
use Bitrix\Crm\Copilot\CallScriptMaintenance\Dispatcher;
use Bitrix\Crm\Copilot\CallScriptMaintenance\State;
use Bitrix\Crm\Copilot\CallScriptMaintenance\StateRepository;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Dto\Scoring\GroupSuspiciousCallsPayload;

final class GroupSuspiciousCallsHandler
{
	/**
	 * @var array<int, int[]> ACTIVITY_ID => SELECTION_ID[]
	 */
	private array $selectionByActivity = [];

	private readonly StateRepository $stateRepo;
	private readonly Dispatcher $dispatcher;

	public function __construct(
		private readonly int $jobId,
		private readonly GroupSuspiciousCallsPayload $payload,
		?StateRepository $stateRepo = null,
		?Dispatcher $dispatcher = null,
	)
	{
		$this->stateRepo = $stateRepo ?? new StateRepository();
		$this->dispatcher = $dispatcher ?? Dispatcher::getInstance();
	}

	public function handle(): void
	{
		$context = $this->stateRepo->popMaintenanceJob($this->jobId, [State::JOB_TYPE_GROUPING]);
		if ($context === null)
		{
			AIManager::logger()->warning(
				'{date}: {class}: stale or foreign grouping callback; job {job}',
				['class' => self::class, 'job' => $this->jobId],
			);

			return;
		}

		$minGroupSize = Config::getGroupingMinGroupSize();
		$this->selectionByActivity = $this->collectSelectionMap($context['selectionIds'] ?? []);
		$existingScriptIds = $this->loadExistingScriptIds();

		foreach ($this->payload->groups as $group)
		{
			$scriptId = (int)($group['scriptId'] ?? 0);
			$items = $group['items'] ?? [];

			if ($scriptId > 0 && isset($existingScriptIds[$scriptId]))
			{
				$this->dispatcher->markGrouped($this->mapSelectionIds($items));

				continue;
			}

			if (count($items) < $minGroupSize)
			{
				continue;
			}

			$dialogues = $this->dispatcher->loadDialogues($items);
			if (empty($dialogues))
			{
				$this->dispatcher->markGrouped($this->mapSelectionIds($items));

				continue;
			}

			$usedActivityIds = array_column($dialogues, 'id');
			$droppedActivityIds = array_values(array_diff($items, $usedActivityIds));
			if (!empty($droppedActivityIds))
			{
				$this->dispatcher->markGrouped($this->mapSelectionIds($droppedActivityIds));
			}

			if (count($dialogues) < $minGroupSize)
			{
				continue;
			}

			$groupSelectionIds = $this->mapSelectionIds($usedActivityIds);
			$childJobId = $this->dispatcher->launchCreateCallAssessmentJob(
				array_column($dialogues, 'transcript'),
				$this->jobId,
			);
			if ($childJobId > 0)
			{
				$this->stateRepo->registerMaintenanceJob($childJobId, [
					'type' => State::JOB_TYPE_CREATE_CALL_ASSESSMENT,
					'selectionIds' => $groupSelectionIds,
					'groupName' => $group['groupName'] ?? null,
				]);
			}
		}

		$this->dispatcher->pingAgent();
	}

	/**
	 * @param int[] $selectionIds
	 * @return array<int, int>
	 */
	private function collectSelectionMap(array $selectionIds): array
	{
		if (empty($selectionIds))
		{
			return [];
		}

		$rows = AiCallScriptSelectionTable::query()
			->setSelect(['ID', 'ACTIVITY_ID'])
			->whereIn('ID', array_map('intval', $selectionIds))
			->fetchAll()
		;

		$map = [];
		foreach ($rows as $row)
		{
			$map[(int)$row['ACTIVITY_ID']][] = (int)$row['ID'];
		}

		return $map;
	}

	/**
	 * @return array<int, true>
	 */
	private function loadExistingScriptIds(): array
	{
		$items = CopilotCallAssessmentController::getInstance()->getList([
			'select' => ['ID'],
			'filter' => ['IS_ENABLED' => 'Y'],
			'order' => ['ID' => 'ASC'],
			'limit' => 1000,
		])->collectValues();

		$result = [];
		foreach ($items as $item)
		{
			$result[(int)$item['ID']] = true;
		}

		return $result;
	}

	private function mapSelectionIds(array $activityIds): array
	{
		$result = [];
		foreach ($activityIds as $activityId)
		{
			foreach ($this->selectionByActivity[$activityId] ?? [] as $selectionId)
			{
				$result[] = $selectionId;
			}
		}

		return $result;
	}
}
