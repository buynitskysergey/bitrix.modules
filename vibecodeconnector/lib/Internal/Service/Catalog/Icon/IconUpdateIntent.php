<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\Icon;

enum IconUpdateIntent: string
{
	case Keep = 'keep';
	case Replace = 'replace';
	case Delete = 'delete';
}
