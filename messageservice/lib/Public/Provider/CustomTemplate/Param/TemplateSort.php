<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Public\Provider\CustomTemplate\Param;

use Bitrix\Main\Provider\Params\Sort;

final class TemplateSort extends Sort
{
	protected function getAllowedFields(): array
	{
		return ['TITLE', 'DATE_CREATE', 'DATE_MODIFY'];
	}
}
