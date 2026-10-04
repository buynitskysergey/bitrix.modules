<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\CallScriptMaintenance\Entity;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;

final class MaintenanceContextTable extends DataManager
{
	use DeleteByFilterTrait;

	public static function getTableName(): string
	{
		return 'b_crm_ai_call_script_maintenance_context';
	}

	public static function getMap(): array
	{
		$fieldRepository = ServiceLocator::getInstance()->get('crm.model.fieldRepository');

		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
			,
			(new IntegerField('JOB_ID'))
				->configureRequired()
				->configureUnique()
			,
			(new StringField('TYPE'))
				->configureSize(32)
				->configureRequired()
			,
			(new TextField('DATA'))
				->configureNullable()
			,
			$fieldRepository->getCreatedTime('CREATED_AT'),
		];
	}

}
