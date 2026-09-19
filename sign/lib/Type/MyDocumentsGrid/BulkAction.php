<?php

namespace Bitrix\Sign\Type\MyDocumentsGrid;

use Bitrix\Sign\Type\ValuesTrait;

enum BulkAction: string
{
	use ValuesTrait;

	case APPROVE = 'approve';
	case REJECT = 'reject';
}
