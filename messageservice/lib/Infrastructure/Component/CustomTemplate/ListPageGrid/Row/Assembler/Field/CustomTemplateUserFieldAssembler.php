<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Infrastructure\Component\CustomTemplate\ListPageGrid\Row\Assembler\Field;

use Bitrix\Main\Grid\Row\Assembler\Field\UserFieldAssembler;

class CustomTemplateUserFieldAssembler extends UserFieldAssembler
{
	private const PROFILE_URL = '/company/personal/user/';

	protected function prepareColumn($value)
	{
		$name = parent::prepareColumn($value);
		if ($name === null || $name === '')
		{
			// id <= 0 (system AUTHOR_ID = 0), MODIFIED_BY = null, or an unresolved user: never
			// fall through to the raw id, which main.ui.grid would echo as the cell HTML.
			return '—';
		}

		$userId = (int)$value;

		return '<a href="' . self::PROFILE_URL . $userId . '/">' . $name . '</a>';
	}
}
