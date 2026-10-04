<?php

namespace Bitrix\Mail\Internals;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\Entity;

class MessageLabelTable extends DataManager
{
	use DeleteByFilterTrait;

	/**
	 * @return string
	 */
	public static function getTableName()
	{
		return 'b_mail_message_label';
	}

	/**
	 * @return array
	 */
	public static function getMap()
	{
		return [
			new Entity\IntegerField('LABEL_ID', [
				'primary' => true,
			]),
			new Entity\IntegerField('MESSAGE_ID', [
				'primary' => true,
			]),
			new Entity\IntegerField('MAILBOX_ID', [
				'required' => true,
			]),
		];
	}
}
