<?php

namespace Bitrix\Mail\Internals;

use Bitrix\Mail\Internals\Entity\UserLabel;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\Entity;

/**
 * Class UserLabelTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_UserLabel_Query query()
 * @method static EO_UserLabel_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_UserLabel_Result getById($id)
 * @method static EO_UserLabel_Result getList(array $parameters = [])
 * @method static EO_UserLabel_Entity getEntity()
 * @method static \Bitrix\Mail\Internals\Entity\UserLabel createObject($setDefaultValues = true)
 * @method static \Bitrix\Mail\Internals\EO_UserLabel_Collection createCollection()
 * @method static \Bitrix\Mail\Internals\Entity\UserLabel wakeUpObject($row)
 * @method static \Bitrix\Mail\Internals\EO_UserLabel_Collection wakeUpCollection($rows)
 */
class UserLabelTable extends DataManager
{
	use DeleteByFilterTrait;

	/**
	 * @return string
	 */
	public static function getTableName()
	{
		return 'b_mail_user_label';
	}

	/**
	 * @return array
	 */
	public static function getMap()
	{
		return [
			new Entity\IntegerField('ID', [
				'primary' => true,
				'autocomplete' => true,
			]),
			new Entity\IntegerField('USER_ID', [
				'required' => true,
			]),
			new Entity\IntegerField('MAILBOX_ID', [
				'default_value' => 0,
			]),
			new Entity\StringField('NAME', [
				'required' => true,
			]),
			new Entity\IntegerField('SORT', [
				'default_value' => 100,
			]),
			new Entity\DatetimeField('DATE_INSERT'),
		];
	}

	/**
	 * @return \Bitrix\Main\ORM\Objectify\EntityObject|string
	 */
	public static function getObjectClass()
	{
		return UserLabel::class;
	}
}
