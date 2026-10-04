<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\EntityError;
use Bitrix\Main\ORM\Event;
use Bitrix\Main\ORM\EventResult;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;
use Bitrix\Main\Type\DateTime;

class StorageDataViewTable extends DataManager
{
	use DeleteByFilterTrait;

	/**
	 * Returns DB table name for entity.
	 *
	 * @return string
	 */
	public static function getTableName(): string
	{
		return 'b_bp_storage_data_view';
	}

	/**
	 * Returns entity map definition.
	 *
	 * @return array
	 */
	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
			,
			(new IntegerField('STORAGE_TYPE_ID'))
				->configureRequired()
			,
			(new TextField('DEFINITION')),
			(new StringField('STATUS'))
				->addValidator(new LengthValidator(null, 1))
				->configureRequired()
				->configureDefaultValue('N')
			,
			(new TextField('ERROR_TEXT'))
				->configureNullable()
			,
			(new TextField('DELETION_MARKS')),
			(new DatetimeField('MATERIALIZED_AT')),
			(new IntegerField('MATERIALIZED_BY')),
			(new IntegerField('OWNER_TEMPLATE_ID'))
				->configureNullable()
			,
			(new StringField('OWNER_ACTIVITY_NAME'))
				->configureNullable()
				->addValidator(new LengthValidator(null, 255))
			,
			(new IntegerField('CREATED_BY'))
				->configureRequired()
			,
			(new IntegerField('UPDATED_BY'))
				->configureRequired()
			,
			(new DatetimeField('CREATED_TIME'))
				->configureRequired()
				->configureDefaultValue(new DateTime())
			,
			(new DatetimeField('UPDATED_TIME'))
				->configureRequired()
				->configureDefaultValue(new DateTime())
			,
		];
	}

	public static function onBeforeAdd(Event $event): EventResult
	{
		$result = new EventResult();
		$fields = $event->getParameter('fields');

		$storageTypeId = (int)($fields['STORAGE_TYPE_ID'] ?? 0);
		if ($storageTypeId > 0 && self::getCount(['=STORAGE_TYPE_ID' => $storageTypeId]) > 0)
		{
			$result->addError(
				new EntityError('A data view definition already exists for this storage type.')
			);
		}

		return $result;
	}
}
