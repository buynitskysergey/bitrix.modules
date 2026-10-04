<?php

declare(strict_types=1);

namespace Bitrix\Main\DB\Ddl\Column;

enum ColumnAutoincrementType
{
	case Identity;
	case SequenceDefault;
	case None;
}
