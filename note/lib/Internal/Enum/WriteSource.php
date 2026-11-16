<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Enum;

enum WriteSource: string
{
	case User = 'user';
	case Workflow = 'workflow';
}
