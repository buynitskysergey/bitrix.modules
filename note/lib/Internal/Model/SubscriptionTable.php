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
 * [P6.T1 / SQL-05] One row per (user, scope, entityId) subscription. Upserted via
 * SubscriptionRepository::upsert() on the UNIQUE(USER_ID, SCOPE, ENTITY_ID) index —
 * Unsubscribe deletes the row instead of flipping a flag, so a subscriber
 * count is always just COUNT(*) and SubscriptionResolver never has to filter out
 * disabled rows.
 *
 * `subtree` is a live rule evaluated at send time (SubscriptionResolver walks
 * DocumentRepository::getAncestorIds()) — no materialized descendant set is
 * stored here.
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_Subscription_Query query()
 * @method static EO_Subscription_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_Subscription_Result getById($id)
 * @method static EO_Subscription_Result getList(array $parameters = [])
 * @method static EO_Subscription_Entity getEntity()
 * @method static \Bitrix\Note\Internal\Model\EO_Subscription createObject($setDefaultValues = true)
 * @method static \Bitrix\Note\Internal\Model\EO_Subscription_Collection createCollection()
 * @method static \Bitrix\Note\Internal\Model\EO_Subscription wakeUpObject($row)
 * @method static \Bitrix\Note\Internal\Model\EO_Subscription_Collection wakeUpCollection($rows)
 */
class SubscriptionTable extends DataManager
{
	use DeleteByFilterTrait;

	public const SCOPE_DOCUMENT = 'document';
	public const SCOPE_COLLECTION = 'collection';

	public const MODE_SELF = 'self';
	public const MODE_SUBTREE = 'subtree';
	public const MODE_ALL = 'all';
	// Negative override: a per-document row that EXCLUDES the user from this document's
	// notifications even when an ancestor subtree / collection subscription would otherwise cover
	// it. Mutually exclusive with self/subtree on the same document (one row per user+scope+entity).
	public const MODE_MUTED = 'muted';

	public static function getTableName(): string
	{
		return 'b_note_subscription';
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
			new StringField('SCOPE', [
				'required' => true,
				'validation' => static fn() => [new LengthValidator(null, 10)],
			]),
			new IntegerField('ENTITY_ID', [
				'required' => true,
			]),
			new StringField('MODE', [
				'required' => true,
				'validation' => static fn() => [new LengthValidator(null, 10)],
			]),
			new DatetimeField('CREATED_AT', [
				'required' => true,
				'default_value' => static fn() => new DateTime(),
			]),
		];
	}
}
