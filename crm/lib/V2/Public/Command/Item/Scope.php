<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\Item;

enum Scope: string
{
	case Manual = 'manual';
	case Rest = 'rest';
	case Automation = 'automation';
	case Import = 'import';
	case Ai = 'ai';
	case System = 'system';
}
