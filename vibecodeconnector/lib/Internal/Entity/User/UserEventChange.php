<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Entity\User;

enum UserEventChange: string
{
	case Fired = 'fired';
	case Restored = 'restored';
	case Deleted = 'deleted';
	case GroupsChanged = 'groups_changed';
}
