<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Fields\BooleanField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;
use Bitrix\Main\Text\Emoji;
use Bitrix\Main\Type\DateTime;

/**
 * Class CollectionTable
 *
 * Fields:
 * <ul>
 * <li> ID int mandatory
 * <li> NAME string(255) mandatory
 * <li> CREATED_BY int mandatory
 * <li> POSITION int mandatory
 * <li> CREATED_AT datetime mandatory
 * <li> UPDATED_BY int mandatory
 * <li> UPDATED_AT datetime mandatory
 * <li> POLICY_LEVEL int optional
 * <li> IS_ARCHIVED bool mandatory
 * </ul>
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_Collection_Query query()
 * @method static EO_Collection_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_Collection_Result getById($id)
 * @method static EO_Collection_Result getList(array $parameters = [])
 * @method static EO_Collection_Entity getEntity()
 * @method static \Bitrix\Note\Internal\Model\Collection createObject($setDefaultValues = true)
 * @method static \Bitrix\Note\Internal\Model\Collections createCollection()
 * @method static \Bitrix\Note\Internal\Model\Collection wakeUpObject($row)
 * @method static \Bitrix\Note\Internal\Model\Collections wakeUpCollection($rows)
 */
class CollectionTable extends DataManager
{
	const MAX_NAME_LENGTH = 255;

	public static function getTableName()
	{
		return 'b_note_collection';
	}

	public static function getObjectClass(): string
	{
		return Collection::class;
	}

	public static function getCollectionClass(): string
	{
		return Collections::class;
	}

	public static function getMap()
	{
		return [
			new IntegerField(
				'ID',
				[
					'primary' => true,
					'autocomplete' => true,
				],
			),
			// Emoji-safe storage: encode 4-byte UTF-8 to :hex: on save, decode on fetch (see DocumentTable).
			(new StringField(
				'NAME',
				[
					'required' => true,
					'validation' => [__CLASS__, 'validateName'],
				],
			))
				->addSaveDataModifier([Emoji::class, 'encode'])
				->addFetchDataModifier([Emoji::class, 'decode']),
			new IntegerField(
				'CREATED_BY',
				[
					'required' => true,
				],
			),
			new IntegerField(
				'POSITION',
				[
					'required' => true,
					'default_value' => 0,
				],
			),
			new DatetimeField(
				'CREATED_AT',
				[
					'required' => true,
					'default_value' => static fn() => new DateTime(),
				],
			),
			new IntegerField(
				'UPDATED_BY',
				[
					'required' => true,
				],
			),
			new DatetimeField(
				'UPDATED_AT',
				[
					'required' => true,
					'default_value' => static fn() => new DateTime(),
				],
			),
			new IntegerField(
				'POLICY_LEVEL',
				[
					'default_value' => 0,
				],
			),
			new BooleanField(
				'IS_ARCHIVED',
				[
					'required' => true,
					'values' => ['N', 'Y'],
					'default_value' => 'N',
				],
			),
			new Reference(
				'CREATED_BY_USER',
				\Bitrix\Main\UserTable::class,
				['=this.CREATED_BY' => 'ref.ID'],
				['join_type' => 'LEFT'],
			),
			new Reference(
				'UPDATED_BY_USER',
				\Bitrix\Main\UserTable::class,
				['=this.UPDATED_BY' => 'ref.ID'],
				['join_type' => 'LEFT'],
			),
		];
	}

	public static function validateName()
	{
		return [
			new LengthValidator(null, static::MAX_NAME_LENGTH),
		];
	}
}
