<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Model\User;

use Bitrix\Main\ORM\Data\AddStrategy\Trait\AddInsertIgnoreTrait;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\IntegerField;

final class UserTable extends DataManager
{
	use AddInsertIgnoreTrait;

	public static function getTableName(): string
	{
		return 'b_vibecodeconnector_user';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),

			(new IntegerField('BITRIX_USER_ID'))
				->configureRequired(),
		];
	}
}
