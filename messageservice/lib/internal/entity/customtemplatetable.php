<?php

namespace Bitrix\MessageService\Internal\Entity;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;
use Bitrix\Main\Text\Emoji;

final class CustomTemplateTable extends DataManager
{
	use DeleteByFilterTrait;

	public static function getTableName(): string
	{
		return 'b_messageservice_custom_template';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),

			(new StringField('ZONE'))
				->configureRequired()
				->configureSize(32),

			(new StringField('SCENE'))
				->configureRequired()
				->configureSize(32),

			(new StringField('TARGET_ID'))
				->configureRequired()
				->configureSize(255)
				->configureDefaultValue(''),

			// TITLE and BODY carry user text that may include emoji. The DB connection charset is
			// utf8mb3, so 4-byte emoji sent over it are mangled to `?` on write regardless of the
			// column charset. Encode them to `:HEX8:` ASCII tokens on save and decode on fetch so
			// they survive the round trip; keeping it on the field makes it transparent to every
			// ORM read/write path (same rationale as im/NotifyGroupTable). ASCII tokens also keep
			// LIKE searches collation-safe — a raw emoji literal over a utf8mb3 connection breaks
			// against the utf8mb4 columns with an "illegal mix of collations" error.
			(new StringField('TITLE'))
				->configureRequired()
				->configureSize(255)
				->addSaveDataModifier(fn ($value) => Emoji::encode($value))
				->addFetchDataModifier(fn ($value) => Emoji::decode($value)),

			(new TextField('BODY'))
				->configureRequired()
				->addSaveDataModifier(fn ($value) => Emoji::encode($value))
				->addFetchDataModifier(fn ($value) => Emoji::decode($value)),

			(new DatetimeField('DATE_CREATE'))
				->configureRequired()
				->configureDefaultValueNow(),

			(new IntegerField('AUTHOR_ID'))
				->configureRequired()
				->configureDefaultValue(0),

			(new DatetimeField('DATE_MODIFY'))
				->configureNullable(),

			(new IntegerField('MODIFIED_BY'))
				->configureNullable(),
		];
	}
}
