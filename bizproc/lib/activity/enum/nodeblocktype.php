<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Activity\Enum;

enum NodeBlockType: string
{
	case BASE_SETTINGS = 'base-settings';
	case CONDITION = 'condition';
	case ACTION = 'action';
	case FILTER = 'filter';
	case OUTPUT = 'output';
	case GROUP = 'group';
	case RELATIONS = 'relations';
	case STORAGES = 'storages';
}
