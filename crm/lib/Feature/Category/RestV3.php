<?php

namespace Bitrix\Crm\Feature\Category;

use Bitrix\Main\Localization\Loc;

class RestV3 extends BaseCategory
{
	public function getName(): string
	{
		return Loc::getMessage('CATEGORY_REST_V3_NAME');
	}

	public function getSort(): int
	{
		return 300;
	}
}
