<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internals\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\AddStrategy\InsertIgnore;
use Bitrix\Main\ORM\Data\AddStrategy\Trait\AddInsertIgnoreTrait;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;

final class MailboxAddressAliasTable extends DataManager
{
	use AddInsertIgnoreTrait;

	protected static function getInsertIgnoreStrategy(): InsertIgnore
	{
		return new InsertIgnore(static::getEntity(), ['MAILBOX_ID', 'EMAIL']);
	}

	public static function getTableName(): string
	{
		return 'b_mail_mailbox_alias';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
			,
			(new IntegerField('MAILBOX_ID'))
				->configureRequired()
			,
			(new StringField('EMAIL'))
				->configureRequired()
				->configureSize(255)
			,
		];
	}
}
