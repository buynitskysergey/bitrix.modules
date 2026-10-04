<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Infrastructure\Stepper;

use Bitrix\Bizproc\Activity\Enum\ResumeWorkflowQueue;
use Bitrix\Bizproc\Infrastructure\Agent\ClearStuckPauseWorkflowAgent;
use Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\WorkflowResumeMessageTable;
use Bitrix\Main\Application;
use Bitrix\Main\Update\Stepper;

/**
 * Fills ITEM_ID of the resume messages enqueued before the column was written.
 *
 * The stuck pause detector looks a resume message up by ITEM_ID alone, so a workflow whose message
 * still has none looks unattended and would be killed. Hence the cleanup agent is registered here, on
 * completion of the backfill, instead of by the updater.
 */
final class ResumeMessageItemIdStepper extends Stepper
{
	protected static $moduleId = 'bizproc';

	private const STEP_ROWS_LIMIT = 500;

	public function execute(array &$option): bool
	{
		if (!Application::getConnection()->isTableExists(WorkflowResumeMessageTable::getTableName()))
		{
			return self::FINISH_EXECUTION;
		}

		$rows = $this->findMessagesWithoutItemId((int)($option['lastId'] ?? 0));

		if (!$rows)
		{
			// an administrator who turned the sweep off while the backfill was running keeps it off
			if (ClearStuckPauseWorkflowAgent::getActiveOptionValue() !== 'N')
			{
				ClearStuckPauseWorkflowAgent::register();
			}

			return self::FINISH_EXECUTION;
		}

		$this->fillItemId($rows);

		$option['lastId'] = (int)$rows[array_key_last($rows)]['ID'];

		return self::CONTINUE_EXECUTION;
	}

	/**
	 * Paginates by ID and not by the filter itself: a payload carrying no workflow id keeps its empty
	 * ITEM_ID and would be selected over and over again.
	 */
	private function findMessagesWithoutItemId(int $lastId): array
	{
		return WorkflowResumeMessageTable::query()
			->setSelect(['ID', 'PAYLOAD'])
			->whereIn('QUEUE_ID', ResumeWorkflowQueue::values())
			->whereNull('ITEM_ID')
			->where('ID', '>', $lastId)
			->setOrder(['ID' => 'ASC'])
			->setLimit(self::STEP_ROWS_LIMIT)
			->fetchAll()
		;
	}

	private function fillItemId(array $rows): void
	{
		$idsByWorkflow = [];

		foreach ($rows as $row)
		{
			$workflowId = self::extractWorkflowId((string)$row['PAYLOAD']);

			if ($workflowId !== null)
			{
				$idsByWorkflow[$workflowId][] = (int)$row['ID'];
			}
		}

		foreach ($idsByWorkflow as $workflowId => $ids)
		{
			// events are skipped on purpose: onBeforeUpdate moves UPDATED_AT, which the detector reads
			// as a requeue of the message
			WorkflowResumeMessageTable::updateMulti($ids, ['ITEM_ID' => (string)$workflowId], ignoreEvents: true);
		}
	}

	private static function extractWorkflowId(string $payload): ?string
	{
		$data = json_decode($payload, true);
		$workflowId = is_array($data) ? ($data['workflowId'] ?? null) : null;

		return is_string($workflowId) && $workflowId !== '' ? $workflowId : null;
	}
}
