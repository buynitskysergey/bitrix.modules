<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\CallAssessment\Summary\Entity;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\Type\DateTime;

final class SummaryStateTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_crm_copilot_call_assessment_summary_state';
	}

	public static function getMap(): array
	{
		$fieldRepository = ServiceLocator::getInstance()->get('crm.model.fieldRepository');

		return [
			$fieldRepository->getId(),
			(new IntegerField('MANAGER_ID'))
				->configureRequired()
			,
			(new StringField('SITUATION_TYPE'))
				->configureRequired()
				->configureSize(32)
			,
			(new IntegerField('LAST_ANCHOR_ID'))
				->configureNullable()
			,
			(new StringField('STATE'))
				->configureNullable()
				->configureSize(16)
			,
			(new DatetimeField('NOTIFIED_AT'))
				->configureRequired()
				->configureDefaultValue(static fn() => new DateTime())
			,
		];
	}
}
