<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Messenger;

enum QueueId: string
{
	case UserListPull = 'vibecodeconnector.user_list_pull';
	case UserEvent = 'vibecodeconnector.user_event';
}
