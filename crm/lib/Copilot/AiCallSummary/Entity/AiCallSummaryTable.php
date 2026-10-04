<?php

namespace Bitrix\Crm\Copilot\AiCallSummary\Entity;

use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\Result;

final class AiCallSummaryTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_crm_ai_call_summary';
	}

	public static function getMap(): array
	{
		$fieldRepository = ServiceLocator::getInstance()->get('crm.model.fieldRepository');

		return [
			$fieldRepository->getId(),
			$fieldRepository->getCreatedTime('CREATED_AT'),
			$fieldRepository->getUpdatedTime('UPDATED_AT'),
			(new IntegerField('ACTIVITY_ID'))
				->configureRequired()
			,
			(new IntegerField('JOB_ID'))
				->configureRequired()
			,
			(new StringField('THEME'))
				->configureSize(255)
			,
			(new StringField('PRODUCT'))
				->configureSize(255)
			,
			(new StringField('INTENT'))
				->configureSize(255)
			,
		];
	}

	public static function deleteByActivityId(int $activityId): void
	{
		$sqlQuery = new SqlExpression(
			/** @lang text */
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
				/** @lang text */
				'DELETE FROM ?# WHERE JOB_ID IN (' . implode(',', array_map('intval', $jobIds)) . ')',
				self::getTableName(),
			);

			Application::getConnection()->query((string)$sqlQuery);

			self::cleanCache();
		}

		return new Result();
	}
}
