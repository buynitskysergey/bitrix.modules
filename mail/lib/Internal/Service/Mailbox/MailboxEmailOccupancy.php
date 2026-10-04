<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Mailbox;

enum MailboxEmailOccupancy
{
	case Free;
	case OccupiedByRequester;
	case OccupiedByOther;
}
