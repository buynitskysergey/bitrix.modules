<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Infrastructure\Agent;

use Bitrix\Bizproc\Internal\Config\LastValues;
use Bitrix\Bizproc\Internal\Model\LastValues\WorkflowLastValuesTable;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Update\Stepper;

/**
 * Stepper deletes snapshots of runs finished before the retention period. Runs regardless of the
 * capture flag: a portal with the mechanism turned off still has to lose its stored values.
 */
class LastValuesCleanupAgent extends Stepper
{
	private const RECORD_LIMIT = 100;

	protected static $moduleId = 'bizproc';

	/**
	 * Run stepper as agent, for periodic runs.
	 */
	public static function runAgent(): string
	{
		self::bind(0);

		return __METHOD__ . '();';
	}

	/**
	 * @inheritDoc
	 */
	public function execute(array &$option)
	{
		$deletedCount = self::deleteExpired(self::RECORD_LIMIT);

		return $deletedCount < self::RECORD_LIMIT ? self::FINISH_EXECUTION : self::CONTINUE_EXECUTION;
	}

	/**
	 * Deletes at most $limit expired snapshots, oldest first, and reports the size of the batch it took.
	 * The number is an upper bound of what is gone: a row rewritten between the selection and the deletion
	 * is left where it is, and one more pass of the stepper costs less than counting the deleted rows.
	 */
	public static function deleteExpired(int $limit): int
	{
		$expiredBefore = (new DateTime())->add('-' . (new LastValues())->getRetentionDays() . ' days');

		$rows = WorkflowLastValuesTable::query()
			->setSelect(['TEMPLATE_ID'])
			->where('COMPLETED_AT', '<', $expiredBefore)
			->setOrder(['COMPLETED_AT' => 'ASC'])
			->setLimit($limit)
			->fetchAll()
		;

		if ($rows === [])
		{
			return 0;
		}

		// the deadline is repeated here: a run of the same template may have rewritten the row between the
		// selection and the deletion, and that snapshot is not expired
		WorkflowLastValuesTable::deleteByFilter([
			'@TEMPLATE_ID' => array_column($rows, 'TEMPLATE_ID'),
			'<COMPLETED_AT' => $expiredBefore,
		]);

		return count($rows);
	}
}
