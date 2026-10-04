<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;
use Bitrix\Main\Type\DateTime;

/**
 * [P1.T1 / SQL-01] One row per (user, entityType, entityId) favorite, written through
 * FavoriteRepository on the UNIQUE(USER_ID, ENTITY_TYPE, ENTITY_ID) index.
 *
 * ENTITY_TYPE repeats the SubscriptionTable::SCOPE_* wording on purpose: the same literal
 * travels between favorites and subscriptions without a mapping table.
 *
 * POSITION belongs to the row (manual drag order, shared by both entity types) and is never
 * derived from CREATED_AT. No foreign keys: the two target types live in different tables.
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_Favorite_Query query()
 * @method static EO_Favorite_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_Favorite_Result getById($id)
 * @method static EO_Favorite_Result getList(array $parameters = [])
 * @method static EO_Favorite_Entity getEntity()
 * @method static \Bitrix\Note\Internal\Model\EO_Favorite createObject($setDefaultValues = true)
 * @method static \Bitrix\Note\Internal\Model\EO_Favorite_Collection createCollection()
 * @method static \Bitrix\Note\Internal\Model\EO_Favorite wakeUpObject($row)
 * @method static \Bitrix\Note\Internal\Model\EO_Favorite_Collection wakeUpCollection($rows)
 */
class FavoriteTable extends DataManager
{
	use DeleteByFilterTrait;

	public const ENTITY_TYPE_DOCUMENT = 'document';
	public const ENTITY_TYPE_COLLECTION = 'collection';

	public static function getTableName(): string
	{
		return 'b_note_favorite';
	}

	public static function getMap(): array
	{
		return [
			new IntegerField('ID', [
				'primary' => true,
				'autocomplete' => true,
			]),
			new IntegerField('USER_ID', [
				'required' => true,
			]),
			new StringField('ENTITY_TYPE', [
				'required' => true,
				'validation' => static fn() => [new LengthValidator(null, 10)],
			]),
			new IntegerField('ENTITY_ID', [
				'required' => true,
			]),
			new IntegerField('POSITION', [
				'required' => true,
				'default_value' => 0,
			]),
			new DatetimeField('CREATED_AT', [
				'required' => true,
				'default_value' => static fn() => new DateTime(),
			]),
		];
	}
}
