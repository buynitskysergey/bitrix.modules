<?php

namespace Bitrix\Crm\Copilot\AiCallScriptSelection\Entity;

use Bitrix\Crm\Copilot\AiCallSummary\Entity\AiCallSummaryTable;
use Bitrix\Crm\Copilot\CallAssessment\Entity\CopilotCallAssessmentTable;
use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

final class AiCallScriptSelectionTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_crm_ai_call_script_selection';
	}

	public static function getMap(): array
	{
		$fieldRepository = ServiceLocator::getInstance()->get('crm.model.fieldRepository');

		return [
			$fieldRepository->getId(),
			$fieldRepository->getCreatedTime('CREATED_AT'),
			(new IntegerField('ACTIVITY_ID'))
				->configureRequired()
			,
			(new IntegerField('ASSESSMENT_SETTING_ID'))
				->configureRequired()
			,
			(new IntegerField('JOB_ID'))
				->configureRequired()
			,
			(new IntegerField('CONFIDENCE'))
				->configureRequired()
				->configureSize(1)
			,
			(new StringField('RATIONALE'))
				->configureSize(1000)
			,
			(new DatetimeField('GROUPED_AT')),
			(new DatetimeField('ENRICHED_AT')),
			new Reference(
				'SUMMARY',
				AiCallSummaryTable::class,
				Join::on('this.ACTIVITY_ID', 'ref.ACTIVITY_ID'),
			),
			new Reference(
				'ASSESSMENT',
				CopilotCallAssessmentTable::class,
				Join::on('this.ASSESSMENT_SETTING_ID', 'ref.ID'),
			),
		];
	}

	public static function deleteByActivityId(int $activityId): void
	{
		$sqlQuery = new SqlExpression(
			'DELETE FROM ?# WHERE ACTIVITY_ID=?i',
			self::getTableName(),
			$activityId,
		);

		Application::getConnection()->query((string)$sqlQuery);

		self::cleanCache();
	}

	public static function deleteByJobIds(array $jobIds): Result
	{
		if (!empty($jobIds))
		{
			$sqlQuery = new SqlExpression(
				'DELETE FROM ?# WHERE JOB_ID IN (' . implode(',', array_map('intval', $jobIds)) . ')',
				self::getTableName(),
			);

			Application::getConnection()->query((string)$sqlQuery);

			self::cleanCache();
		}

		return new Result();
	}

	public static function markGroupedByIds(array $ids): void
	{
		self::touchDatetimeByIds('GROUPED_AT', $ids);
	}

	public static function markEnrichedByIds(array $ids): void
	{
		self::touchDatetimeByIds('ENRICHED_AT', $ids);
	}

	private static function touchDatetimeByIds(string $column, array $ids): void
	{
		$ids = array_values(array_filter(
			array_unique(array_map('intval', $ids)),
			static fn ($id) => $id > 0,
		));
		if (empty($ids))
		{
			return;
		}

		self::updateMulti($ids, [$column => new DateTime()]);
	}
}
