<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internals;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;

final class TaskMailSourceTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_mail_task_source';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('TASK_ID'))
				->configurePrimary()
			,
			(new StringField('SOURCE_TYPE'))
				->configureRequired()
				->configureSize(32)
			,
			(new IntegerField('SOURCE_ID'))
				->configureRequired()
			,
		];
	}
}
